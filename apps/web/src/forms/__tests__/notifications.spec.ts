import { describe, expect, it } from 'vitest'
import type { NotificationSettings } from '@tresso/core'
import { mailNotificationPatch, mailNotificationValues } from '../notifications'

function settings(extra: Partial<NotificationSettings>): NotificationSettings {
  return { channelsByKind: {}, holdMailInMeetings: true, ...extra } as NotificationSettings
}

describe('mail notification settings', () => {
  it('starts from the default channels until the user chose', () => {
    expect(mailNotificationValues(settings({}))).toEqual({ channels: ['phone', 'desktop'], holdInMeetings: true })
    expect(mailNotificationValues(settings({ channelsByKind: { mail: [], brief: ['email'] }, holdMailInMeetings: false }))).toEqual({ channels: [], holdInMeetings: false })
  })

  it('saves the choice for mail only, so other kinds keep theirs', () => {
    expect(mailNotificationPatch({ channels: ['email'], holdInMeetings: false })).toEqual({ channelsByKind: { mail: ['email'] }, holdMailInMeetings: false })
  })
})
