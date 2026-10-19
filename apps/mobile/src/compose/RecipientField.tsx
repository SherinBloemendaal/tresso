import { useEffect, useMemo, useRef, useState, type ComponentRef } from 'react'
import { Pressable, StyleSheet, Text, TextInput, View, type TextInputKeyPressEvent } from 'react-native'
import { isPlausibleAddress, parseAddresses, type Address, type Contact } from '@tresso/core'
import { Icon } from '@/components/Icon'
import { useTheme, type Theme } from '@/theme'

interface Props {
  label: string
  recipients: Address[]
  onChange(recipients: Address[]): void
  suggest(query: string): Promise<Contact[]>
  /** Shown at the end of the row, e.g. the Cc Bcc toggle. */
  trailing?: React.ReactNode
  autoFocus?: boolean
}

function shown(address: Address): string {
  return address.name ?? address.address
}

/**
 * Recipients as chips, with suggestions from the people the user exchanged mail with. Typed addresses become chips on
 * return, a comma or a semicolon; pasted lists are split; an empty field's backspace removes the last chip.
 */
export function RecipientField({ label, recipients, onChange, suggest, trailing, autoFocus }: Props) {
  const theme = useTheme()
  const styles = useMemo(() => makeStyles(theme), [theme])
  const [text, setText] = useState('')
  const [suggestions, setSuggestions] = useState<{ query: string; contacts: Contact[] }>({ query: '', contacts: [] })
  const input = useRef<ComponentRef<typeof TextInput>>(null)
  const query = text.trim()

  useEffect(() => {
    if (query === '') {
      return
    }
    let current = true
    void suggest(query).then((contacts) => {
      if (current) {
        setSuggestions({ query, contacts })
      }
    })
    return () => {
      current = false
    }
  }, [query, suggest])

  const taken = new Set(recipients.map((recipient) => recipient.address.toLowerCase()))
  // Only the suggestions for what is typed now; none while the field is empty.
  const shownSuggestions = suggestions.query === query && query !== '' ? suggestions.contacts.filter((contact) => !taken.has(contact.key)).slice(0, 5) : []

  /** What the field holds, as of the last keystroke: key events can arrive before a render shows it. */
  const typed = useRef('')

  function setTyped(value: string): void {
    typed.current = value
    setText(value)
  }

  function add(addresses: Address[]): void {
    const seen = new Set(taken)
    const fresh: Address[] = []
    for (const address of addresses) {
      const key = address.address.trim().toLowerCase()
      // A pasted list naming someone twice adds them once.
      if (key !== '' && !seen.has(key)) {
        seen.add(key)
        fresh.push({ address: address.address.trim(), name: address.name })
      }
    }
    if (fresh.length > 0) {
      onChange([...recipients, ...fresh])
    }
    setTyped('')
  }

  function changeText(value: string): void {
    // A separator (typed or in pasted text) turns what came before it into chips.
    if (/[,;\n]/.test(value)) {
      const parts = value.split(/[,;\n]/)
      const rest = parts.pop() ?? ''
      add(parseAddresses(parts.join(',')))
      setTyped(rest.trimStart())
      return
    }
    setTyped(value)
  }

  function commit(): void {
    if (text.trim() !== '') {
      add(parseAddresses(text))
    }
  }

  function keyPress(event: TextInputKeyPressEvent): void {
    // Key events come before the text changes, so an empty field here was empty before this backspace.
    if (event.nativeEvent.key === 'Backspace' && typed.current === '' && recipients.length > 0) {
      onChange(recipients.slice(0, -1))
    }
  }

  return (
    <View>
      <View style={styles.row}>
        <Text style={styles.label} onPress={() => input.current?.focus()}>
          {label}
        </Text>
        <View style={styles.chips}>
          {recipients.map((recipient, index) => {
            const valid = isPlausibleAddress(recipient.address)
            return (
              <View key={`${recipient.address}-${index}`} style={[styles.chip, !valid && styles.chipWrong]}>
                <Text
                  style={[styles.chipText, !valid && styles.chipTextWrong]}
                  numberOfLines={1}
                  accessibilityLabel={`${shown(recipient)}, ${recipient.address}${valid ? '' : ', not a valid address'}`}
                >
                  {shown(recipient)}
                </Text>
                <Pressable
                  style={styles.remove}
                  onPress={() => onChange(recipients.filter((_, other) => other !== index))}
                  accessibilityRole="button"
                  accessibilityLabel={`Remove ${shown(recipient)}`}
                  hitSlop={6}
                >
                  <Icon name="close" size={12} strokeWidth={2.4} color={theme.ink2} />
                </Pressable>
              </View>
            )
          })}
          <TextInput
            ref={input}
            style={styles.input}
            value={text}
            onChangeText={changeText}
            onSubmitEditing={commit}
            onBlur={commit}
            onKeyPress={keyPress}
            submitBehavior="submit"
            keyboardType="email-address"
            autoCapitalize="none"
            autoCorrect={false}
            autoComplete="email"
            textContentType="emailAddress"
            returnKeyType="next"
            autoFocus={autoFocus}
            accessibilityLabel={label}
          />
        </View>
        {trailing}
      </View>
      {shownSuggestions.length > 0 && (
        <View style={styles.suggestions} accessibilityRole="list">
          {shownSuggestions.map((contact) => (
            <Pressable
              key={contact.key}
              style={({ pressed }) => [styles.suggestion, pressed && styles.pressed]}
              onPress={() => add([{ address: contact.address, name: contact.name }])}
              accessibilityRole="button"
              accessibilityLabel={`Add ${contact.name ?? contact.address}, ${contact.address}`}
            >
              {contact.name !== null && (
                <Text style={styles.suggestionName} numberOfLines={1}>
                  {contact.name}
                </Text>
              )}
              <Text style={styles.suggestionAddress} numberOfLines={1}>
                {contact.address}
              </Text>
            </Pressable>
          ))}
        </View>
      )}
    </View>
  )
}

function makeStyles(theme: Theme) {
  return StyleSheet.create({
    row: { flexDirection: 'row', alignItems: 'flex-start', gap: 10, minHeight: 52, paddingHorizontal: 16, paddingVertical: 10, borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: theme.line },
    label: { width: 52, paddingTop: 6, fontSize: 14, color: theme.ink2 },
    chips: { flex: 1, minWidth: 0, flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', gap: 6 },
    chip: { flexDirection: 'row', alignItems: 'center', gap: 2, height: 30, paddingLeft: 10, paddingRight: 2, borderRadius: 999, backgroundColor: theme.ground, maxWidth: '100%' },
    chipWrong: { backgroundColor: theme.surface, borderWidth: 1, borderColor: theme.danger },
    chipText: { fontSize: 13, fontWeight: '500', color: theme.ink, flexShrink: 1 },
    chipTextWrong: { color: theme.danger },
    remove: { width: 28, height: 28, alignItems: 'center', justifyContent: 'center', borderRadius: 999 },
    input: { flexGrow: 1, minWidth: 80, height: 30, paddingVertical: 0, fontSize: 15, color: theme.ink },
    suggestions: { marginLeft: 78, marginRight: 16, marginBottom: 8, borderRadius: 10, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface, overflow: 'hidden' },
    suggestion: { minHeight: 48, paddingHorizontal: 12, paddingVertical: 6, justifyContent: 'center', borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: theme.line },
    suggestionName: { fontSize: 14, fontWeight: '600', color: theme.ink },
    suggestionAddress: { fontSize: 13, color: theme.ink2 },
    pressed: { backgroundColor: theme.ground },
  })
}
