// Package ical reads and writes the iCalendar text (RFC 5545) the provider calendar syncers exchange with the API.
// It knows content lines, parameters, components and text escaping; what the properties mean is up to the callers,
// and the API (sabre/vobject) remains the one full iCalendar implementation.
package ical

import (
	"errors"
	"fmt"
	"strconv"
	"strings"
	"time"
	"unicode/utf8"
)

// Param is one property parameter; Values holds the comma-separated values unquoted.
type Param struct {
	Name   string
	Values []string
}

type Property struct {
	Name   string
	Params []Param
	// Value is the raw value as it appears in the content line (TEXT values still escaped).
	Value string
}

// Param returns the first value of the named parameter, or "".
func (p Property) Param(name string) string {
	for _, param := range p.Params {
		if strings.EqualFold(param.Name, name) && len(param.Values) > 0 {
			return param.Values[0]
		}
	}
	return ""
}

// Text is the value read as TEXT (RFC 5545 3.3.11).
func (p Property) Text() string {
	return UnescapeText(p.Value)
}

type Component struct {
	Name     string
	Props    []Property
	Children []*Component
}

// Get returns the first property with the name, or nil.
func (c *Component) Get(name string) *Property {
	for i := range c.Props {
		if strings.EqualFold(c.Props[i].Name, name) {
			return &c.Props[i]
		}
	}
	return nil
}

// All returns every property with the name.
func (c *Component) All(name string) []Property {
	var out []Property
	for _, p := range c.Props {
		if strings.EqualFold(p.Name, name) {
			out = append(out, p)
		}
	}
	return out
}

// Text is the TEXT value of the first property with the name, or "".
func (c *Component) Text(name string) string {
	if p := c.Get(name); p != nil {
		return p.Text()
	}
	return ""
}

// Add appends a property with a raw value.
func (c *Component) Add(name, value string, params ...Param) {
	c.Props = append(c.Props, Property{Name: name, Params: params, Value: value})
}

// AddText appends a TEXT property, escaping the value; an empty value is left out.
func (c *Component) AddText(name, value string, params ...Param) {
	if value == "" {
		return
	}
	c.Add(name, EscapeText(value), params...)
}

// Remove drops every property with the name.
func (c *Component) Remove(name string) {
	kept := c.Props[:0]
	for _, p := range c.Props {
		if !strings.EqualFold(p.Name, name) {
			kept = append(kept, p)
		}
	}
	c.Props = kept
}

// Components returns the direct children with the name.
func (c *Component) Components(name string) []*Component {
	var out []*Component
	for _, child := range c.Children {
		if strings.EqualFold(child.Name, name) {
			out = append(out, child)
		}
	}
	return out
}

// MaxSize bounds the text Parse reads; the API takes no larger resource.
const MaxSize = 1 << 20

var errSyntax = errors.New("ical: malformed content")

// Parse reads one iCalendar object (usually a VCALENDAR).
func Parse(text string) (*Component, error) {
	if len(text) > MaxSize {
		return nil, fmt.Errorf("ical: %d bytes is more than the %d taken", len(text), MaxSize)
	}
	var root *Component
	var stack []*Component
	for _, line := range unfold(text) {
		if strings.TrimSpace(line) == "" {
			continue
		}
		prop, err := parseLine(line)
		if err != nil {
			return nil, err
		}
		switch strings.ToUpper(prop.Name) {
		case "BEGIN":
			child := &Component{Name: strings.ToUpper(prop.Value)}
			if len(stack) == 0 {
				if root != nil {
					return nil, fmt.Errorf("%w: more than one object", errSyntax)
				}
				root = child
			} else {
				parent := stack[len(stack)-1]
				parent.Children = append(parent.Children, child)
			}
			stack = append(stack, child)
		case "END":
			if len(stack) == 0 || !strings.EqualFold(stack[len(stack)-1].Name, prop.Value) {
				return nil, fmt.Errorf("%w: END:%s without its BEGIN", errSyntax, prop.Value)
			}
			stack = stack[:len(stack)-1]
		default:
			if len(stack) == 0 {
				return nil, fmt.Errorf("%w: property outside a component", errSyntax)
			}
			current := stack[len(stack)-1]
			current.Props = append(current.Props, prop)
		}
	}
	if root == nil || len(stack) != 0 {
		return nil, fmt.Errorf("%w: incomplete object", errSyntax)
	}
	return root, nil
}

func unfold(text string) []string {
	text = strings.ReplaceAll(text, "\r\n", "\n")
	var lines []string
	for _, raw := range strings.Split(text, "\n") {
		if (strings.HasPrefix(raw, " ") || strings.HasPrefix(raw, "\t")) && len(lines) > 0 {
			lines[len(lines)-1] += raw[1:]
			continue
		}
		lines = append(lines, strings.TrimSuffix(raw, "\r"))
	}
	return lines
}

// ParseContentLine reads one unfolded content line, such as an RRULE line of a Google recurrence.
func ParseContentLine(line string) (Property, error) {
	return parseLine(strings.TrimSpace(line))
}

// parseLine splits name *(";" param) ":" value, honouring quoted parameter values.
func parseLine(line string) (Property, error) {
	var prop Property
	i := strings.IndexAny(line, ";:")
	if i <= 0 {
		return prop, fmt.Errorf("%w: %q", errSyntax, truncate(line, 40))
	}
	prop.Name = strings.ToUpper(line[:i])
	rest := line[i:]
	for strings.HasPrefix(rest, ";") {
		rest = rest[1:]
		eq := strings.IndexByte(rest, '=')
		if eq <= 0 {
			return prop, fmt.Errorf("%w: parameter in %q", errSyntax, truncate(line, 40))
		}
		param := Param{Name: strings.ToUpper(rest[:eq])}
		rest = rest[eq+1:]
		for {
			var value string
			if strings.HasPrefix(rest, `"`) {
				end := strings.IndexByte(rest[1:], '"')
				if end < 0 {
					return prop, fmt.Errorf("%w: unclosed quote in %q", errSyntax, truncate(line, 40))
				}
				value, rest = rest[1:end+1], rest[end+2:]
			} else {
				end := strings.IndexAny(rest, ",;:")
				if end < 0 {
					return prop, fmt.Errorf("%w: %q", errSyntax, truncate(line, 40))
				}
				value, rest = rest[:end], rest[end:]
			}
			param.Values = append(param.Values, value)
			if !strings.HasPrefix(rest, ",") {
				break
			}
			rest = rest[1:]
		}
		prop.Params = append(prop.Params, param)
	}
	if !strings.HasPrefix(rest, ":") {
		return prop, fmt.Errorf("%w: %q", errSyntax, truncate(line, 40))
	}
	prop.Value = rest[1:]
	return prop, nil
}

// Line is the property as one unfolded content line.
func (p Property) Line() string {
	var line strings.Builder
	line.WriteString(p.Name)
	for _, param := range p.Params {
		line.WriteString(";" + param.Name + "=")
		for i, v := range param.Values {
			if i > 0 {
				line.WriteByte(',')
			}
			line.WriteString(quoteParam(v))
		}
	}
	line.WriteString(":" + p.Value)
	return line.String()
}

// Serialize writes the component as iCalendar text with CRLF line ends, folded at 75 octets.
func (c *Component) Serialize() string {
	var b strings.Builder
	c.write(&b)
	return b.String()
}

func (c *Component) write(b *strings.Builder) {
	writeLine(b, "BEGIN:"+c.Name)
	for _, p := range c.Props {
		writeLine(b, p.Line())
	}
	for _, child := range c.Children {
		child.write(b)
	}
	writeLine(b, "END:"+c.Name)
}

func quoteParam(v string) string {
	v = strings.ReplaceAll(v, `"`, "'")
	if strings.ContainsAny(v, ";:,") {
		return `"` + v + `"`
	}
	return v
}

// writeLine folds at 75 octets without splitting a UTF-8 sequence.
func writeLine(b *strings.Builder, line string) {
	limit := 75
	for len(line) > limit {
		cut := limit
		for cut > 0 && !utf8.RuneStart(line[cut]) {
			cut--
		}
		b.WriteString(line[:cut] + "\r\n ")
		line = line[cut:]
		limit = 74
	}
	b.WriteString(line + "\r\n")
}

// EscapeText escapes a TEXT value.
func EscapeText(s string) string {
	s = strings.ReplaceAll(s, "\r\n", "\n")
	return strings.NewReplacer(`\`, `\\`, ";", `\;`, ",", `\,`, "\n", `\n`).Replace(s)
}

// UnescapeText reads a TEXT value.
func UnescapeText(s string) string {
	if !strings.Contains(s, `\`) {
		return s
	}
	var b strings.Builder
	for i := 0; i < len(s); i++ {
		if s[i] == '\\' && i+1 < len(s) {
			i++
			switch s[i] {
			case 'n', 'N':
				b.WriteByte('\n')
			default:
				b.WriteByte(s[i])
			}
			continue
		}
		b.WriteByte(s[i])
	}
	return b.String()
}

// Calendar returns an empty VCALENDAR with the version and product id.
func Calendar() *Component {
	c := &Component{Name: "VCALENDAR"}
	c.Add("VERSION", "2.0")
	c.Add("PRODID", "-//Tresso//Calendar sync//EN")
	return c
}

// Events returns the VEVENTs of a VCALENDAR.
func (c *Component) Events() []*Component {
	return c.Components("VEVENT")
}

// Master returns the VEVENT without RECURRENCE-ID, or nil.
func (c *Component) Master() *Component {
	for _, event := range c.Events() {
		if event.Get("RECURRENCE-ID") == nil {
			return event
		}
	}
	return nil
}

// Overrides returns the VEVENTs with a RECURRENCE-ID.
func (c *Component) Overrides() []*Component {
	var out []*Component
	for _, event := range c.Events() {
		if event.Get("RECURRENCE-ID") != nil {
			out = append(out, event)
		}
	}
	return out
}

// Time is a DATE or DATE-TIME value: a date (all-day), a UTC instant, a wall clock in a zone, or a floating time.
type Time struct {
	// T holds the instant for UTC and zoned values, the wall clock (in UTC) for floating ones and the date at
	// midnight UTC for dates.
	T        time.Time
	Date     bool
	Floating bool
	// Zone is the IANA name the TZID mapped to; empty for UTC, floating and dates.
	Zone string
	// TZID is the zone as the property named it.
	TZID string
}

// ZoneOf resolves a TZID: an IANA name, or a Windows name as Exchange writes it.
func ZoneOf(tzid string) (*time.Location, string, error) {
	name := strings.Trim(tzid, `"`)
	if iana, ok := windowsZones[name]; ok {
		name = iana
	}
	if name == "" || name == "Local" {
		return nil, "", fmt.Errorf("ical: unknown time zone %q", tzid)
	}
	loc, err := time.LoadLocation(name)
	if err != nil {
		return nil, "", fmt.Errorf("ical: unknown time zone %q", tzid)
	}
	return loc, name, nil
}

// WindowsToIANA maps a Windows zone name to its IANA name; other names come back unchanged.
func WindowsToIANA(name string) string {
	if iana, ok := windowsZones[name]; ok {
		return iana
	}
	return name
}

// ParseTime reads a DATE or DATE-TIME property. A TZID the worker does not know is read as UTC wall clock and
// reported, so the caller can refuse the event rather than move it.
func ParseTime(p Property) (Time, error) {
	value := strings.TrimSpace(p.Value)
	if i := strings.IndexByte(value, ','); i >= 0 {
		value = value[:i]
	}
	if strings.EqualFold(p.Param("VALUE"), "DATE") || len(value) == 8 {
		t, err := time.Parse("20060102", value)
		if err != nil {
			return Time{}, fmt.Errorf("ical: %s: %w", p.Name, err)
		}
		return Time{T: t, Date: true}, nil
	}
	if strings.HasSuffix(value, "Z") {
		t, err := time.Parse("20060102T150405Z", value)
		if err != nil {
			return Time{}, fmt.Errorf("ical: %s: %w", p.Name, err)
		}
		return Time{T: t}, nil
	}
	tzid := p.Param("TZID")
	if tzid == "" {
		t, err := time.Parse("20060102T150405", value)
		if err != nil {
			return Time{}, fmt.Errorf("ical: %s: %w", p.Name, err)
		}
		return Time{T: t, Floating: true}, nil
	}
	loc, name, err := ZoneOf(tzid)
	if err != nil {
		return Time{}, err
	}
	t, err := time.ParseInLocation("20060102T150405", value, loc)
	if err != nil {
		return Time{}, fmt.Errorf("ical: %s: %w", p.Name, err)
	}
	return Time{T: t, Zone: name, TZID: tzid}, nil
}

// ParseTimes reads every value of a list property such as EXDATE or RDATE (DATE or DATE-TIME values only).
func ParseTimes(p Property) ([]Time, error) {
	var out []Time
	for _, value := range strings.Split(p.Value, ",") {
		value = strings.TrimSpace(value)
		if value == "" {
			continue
		}
		single := p
		single.Value = value
		t, err := ParseTime(single)
		if err != nil {
			return nil, err
		}
		out = append(out, t)
	}
	return out, nil
}

// TimeProperty builds a DATE or DATE-TIME property.
func TimeProperty(name string, t Time) Property {
	switch {
	case t.Date:
		return Property{Name: name, Params: []Param{{Name: "VALUE", Values: []string{"DATE"}}}, Value: t.T.Format("20060102")}
	case t.Floating:
		return Property{Name: name, Value: t.T.Format("20060102T150405")}
	case t.Zone == "":
		return Property{Name: name, Value: t.T.UTC().Format("20060102T150405Z")}
	default:
		tzid := t.TZID
		if tzid == "" {
			tzid = t.Zone
		}
		loc, err := time.LoadLocation(t.Zone)
		if err != nil {
			return Property{Name: name, Value: t.T.UTC().Format("20060102T150405Z")}
		}
		return Property{Name: name, Params: []Param{{Name: "TZID", Values: []string{tzid}}}, Value: t.T.In(loc).Format("20060102T150405")}
	}
}

// RecurrenceKey is how the API names an occurrence: the UTC instant YYYYMMDDTHHMMSSZ, or YYYYMMDD for dates. A
// floating time is keyed by its wall clock.
func RecurrenceKey(t Time) string {
	if t.Date {
		return t.T.Format("20060102")
	}
	return t.T.UTC().Format("20060102T150405Z")
}

// Address is a CAL-ADDRESS value without its mailto: scheme, lowercased.
func Address(value string) string {
	value = strings.TrimSpace(value)
	if len(value) >= 7 && strings.EqualFold(value[:7], "mailto:") {
		value = value[7:]
	}
	return strings.ToLower(value)
}

// ParseDuration reads a DURATION value (RFC 5545 3.3.6), such as -PT15M or P1DT2H.
func ParseDuration(value string) (time.Duration, error) {
	v := strings.ToUpper(strings.TrimSpace(value))
	sign := time.Duration(1)
	switch {
	case strings.HasPrefix(v, "-"):
		sign, v = -1, v[1:]
	case strings.HasPrefix(v, "+"):
		v = v[1:]
	}
	if !strings.HasPrefix(v, "P") || len(v) < 3 {
		return 0, fmt.Errorf("ical: duration %q", value)
	}
	v = v[1:]
	var total time.Duration
	inTime := false
	number := ""
	for _, r := range v {
		switch {
		case r >= '0' && r <= '9':
			number += string(r)
			continue
		case r == 'T':
			inTime = true
			continue
		}
		if number == "" {
			return 0, fmt.Errorf("ical: duration %q", value)
		}
		n, err := strconv.Atoi(number)
		if err != nil {
			return 0, fmt.Errorf("ical: duration %q", value)
		}
		number = ""
		unit := map[rune]time.Duration{'W': 7 * 24 * time.Hour, 'D': 24 * time.Hour}[r]
		if inTime {
			unit = map[rune]time.Duration{'H': time.Hour, 'M': time.Minute, 'S': time.Second}[r]
		}
		if unit == 0 {
			return 0, fmt.Errorf("ical: duration %q", value)
		}
		total += time.Duration(n) * unit
	}
	if number != "" {
		return 0, fmt.Errorf("ical: duration %q", value)
	}
	return sign * total, nil
}

// End is the event's end: DTEND, else DTSTART plus DURATION, else the start (a day later for a date).
func End(event *Component, start Time) (Time, error) {
	if p := event.Get("DTEND"); p != nil {
		return ParseTime(*p)
	}
	end := start
	if p := event.Get("DURATION"); p != nil {
		d, err := ParseDuration(p.Value)
		if err != nil {
			return Time{}, err
		}
		if start.Date {
			end.T = start.T.AddDate(0, 0, int(d/(24*time.Hour)))
		} else {
			end.T = start.T.Add(d)
		}
		return end, nil
	}
	if start.Date {
		end.T = start.T.AddDate(0, 0, 1)
	}
	return end, nil
}

// Alarm is a VALARM that fires a number of minutes before the start.
type Alarm struct {
	Minutes int
	Email   bool
}

// Alarms returns the event's alarms relative to its start, in order; others (absolute, after the end) are left out.
func Alarms(event *Component) []Alarm {
	var out []Alarm
	for _, alarm := range event.Components("VALARM") {
		trigger := alarm.Get("TRIGGER")
		if trigger == nil || strings.EqualFold(trigger.Param("RELATED"), "END") || strings.EqualFold(trigger.Param("VALUE"), "DATE-TIME") {
			continue
		}
		d, err := ParseDuration(trigger.Value)
		if err != nil || d > 0 {
			continue
		}
		out = append(out, Alarm{Minutes: int(-d / time.Minute), Email: strings.EqualFold(alarm.Text("ACTION"), "EMAIL")})
	}
	return out
}

func truncate(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return s[:n]
}
