package caldav

import (
	"strings"
)

type multistatus struct {
	Responses []response `xml:"DAV: response"`
	SyncToken string     `xml:"DAV: sync-token"`
}

type response struct {
	Hrefs     []string   `xml:"DAV: href"`
	Status    string     `xml:"DAV: status"`
	Propstats []propstat `xml:"DAV: propstat"`
}

type propstat struct {
	Prop   prop   `xml:"DAV: prop"`
	Status string `xml:"DAV: status"`
}

type hrefs struct {
	Hrefs []string `xml:"DAV: href"`
}

type prop struct {
	ResourceType         resourceType `xml:"DAV: resourcetype"`
	DisplayName          string       `xml:"DAV: displayname"`
	CurrentUserPrincipal hrefs        `xml:"DAV: current-user-principal"`
	CalendarHomeSet      hrefs        `xml:"urn:ietf:params:xml:ns:caldav calendar-home-set"`
	ScheduleDefault      hrefs        `xml:"urn:ietf:params:xml:ns:caldav schedule-default-calendar-URL"`
	CalendarColor        string       `xml:"http://apple.com/ns/ical/ calendar-color"`
	CTag                 string       `xml:"http://calendarserver.org/ns/ getctag"`
	SyncToken            string       `xml:"DAV: sync-token"`
	ETag                 string       `xml:"DAV: getetag"`
	CalendarData         string       `xml:"urn:ietf:params:xml:ns:caldav calendar-data"`
	CalendarTimezone     string       `xml:"urn:ietf:params:xml:ns:caldav calendar-timezone"`
	Components           compSet      `xml:"urn:ietf:params:xml:ns:caldav supported-calendar-component-set"`
	Privileges           privileges   `xml:"DAV: current-user-privilege-set"`
}

type resourceType struct {
	Collection *struct{} `xml:"DAV: collection"`
	Calendar   *struct{} `xml:"urn:ietf:params:xml:ns:caldav calendar"`
}

type compSet struct {
	Comps []struct {
		Name string `xml:"name,attr"`
	} `xml:"urn:ietf:params:xml:ns:caldav comp"`
}

type privileges struct {
	Privileges []struct {
		All          *struct{} `xml:"DAV: all"`
		Write        *struct{} `xml:"DAV: write"`
		WriteContent *struct{} `xml:"DAV: write-content"`
		Bind         *struct{} `xml:"DAV: bind"`
	} `xml:"DAV: privilege"`
}

// okProp merges the properties of every 2xx propstat of a response.
func (r response) okProp() (prop, bool) {
	var merged prop
	found := false
	for _, ps := range r.Propstats {
		if !statusOK(ps.Status) {
			continue
		}
		found = true
		p := ps.Prop
		if p.ResourceType.Collection != nil || p.ResourceType.Calendar != nil {
			merged.ResourceType = p.ResourceType
		}
		merged.DisplayName = first(merged.DisplayName, p.DisplayName)
		if len(p.CurrentUserPrincipal.Hrefs) > 0 {
			merged.CurrentUserPrincipal = p.CurrentUserPrincipal
		}
		if len(p.CalendarHomeSet.Hrefs) > 0 {
			merged.CalendarHomeSet = p.CalendarHomeSet
		}
		if len(p.ScheduleDefault.Hrefs) > 0 {
			merged.ScheduleDefault = p.ScheduleDefault
		}
		merged.CalendarColor = first(merged.CalendarColor, p.CalendarColor)
		merged.CTag = first(merged.CTag, p.CTag)
		merged.SyncToken = first(merged.SyncToken, p.SyncToken)
		merged.ETag = first(merged.ETag, p.ETag)
		merged.CalendarData = first(merged.CalendarData, p.CalendarData)
		merged.CalendarTimezone = first(merged.CalendarTimezone, p.CalendarTimezone)
		if len(p.Components.Comps) > 0 {
			merged.Components = p.Components
		}
		if len(p.Privileges.Privileges) > 0 {
			merged.Privileges = p.Privileges
		}
	}
	return merged, found
}

func (p prop) writable() bool {
	if len(p.Privileges.Privileges) == 0 {
		// Servers that do not report privileges let the owner write.
		return true
	}
	for _, privilege := range p.Privileges.Privileges {
		if privilege.All != nil || privilege.Write != nil || privilege.WriteContent != nil || privilege.Bind != nil {
			return true
		}
	}
	return false
}

func (p prop) supportsEvents() bool {
	if len(p.Components.Comps) == 0 {
		return true
	}
	for _, comp := range p.Components.Comps {
		if strings.EqualFold(comp.Name, "VEVENT") {
			return true
		}
	}
	return false
}

// statusOK reads "HTTP/1.1 200 OK"; a missing status counts as OK, as in a propstat-less response.
func statusOK(status string) bool {
	fields := strings.Fields(status)
	return len(fields) < 2 || strings.HasPrefix(fields[1], "2")
}

func statusCode(status string) string {
	fields := strings.Fields(status)
	if len(fields) < 2 {
		return ""
	}
	return fields[1]
}

func first(a, b string) string {
	if a != "" {
		return a
	}
	return strings.TrimSpace(b)
}
