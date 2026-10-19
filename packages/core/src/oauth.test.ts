import { describe, expect, it, vi } from 'vitest'
import { codeChallenge, exchangeCode, OAuthError, parseCallback, startAuthorization } from './oauth'

const config = { apiUrl: 'http://api.test/', clientId: 'web', redirectUri: 'http://app.test/auth/callback' }

describe('PKCE', () => {
  it('derives the S256 challenge as base64url(sha256(verifier)) without padding', async () => {
    // Expected value cross-checked with Python: base64.urlsafe_b64encode(hashlib.sha256(v).digest()).rstrip(b'=')
    expect(await codeChallenge('dBjftJeZ4CVP-mJ92K1i8DgNXCPP2yuKoaFT0dcwAAc')).toBe(
      'LG8uW6-wPvkqy07ektBRBnOh8P8NoW3LWcphpLhgKGA',
    )
  })

  it('builds an authorize URL with S256 challenge and state', async () => {
    const pending = await startAuthorization(config)
    const url = new URL(pending.url)

    expect(url.origin + url.pathname).toBe('http://api.test/oauth/authorize')
    expect(url.searchParams.get('client_id')).toBe('web')
    expect(url.searchParams.get('code_challenge_method')).toBe('S256')
    expect(url.searchParams.get('code_challenge')).toBe(await codeChallenge(pending.codeVerifier))
    expect(url.searchParams.get('state')).toBe(pending.state)
    expect(pending.codeVerifier.length).toBeGreaterThanOrEqual(43)
  })
})

describe('parseCallback', () => {
  it('returns the code when the state matches', () => {
    expect(parseCallback('http://app.test/auth/callback?code=abc&state=s1', 's1')).toBe('abc')
  })

  it('rejects a state mismatch', () => {
    expect(() => parseCallback('http://app.test/auth/callback?code=abc&state=other', 's1')).toThrow(OAuthError)
  })

  it('surfaces authorization server errors', () => {
    expect(() =>
      parseCallback('http://app.test/auth/callback?error=access_denied&error_description=Denied&state=s1', 's1'),
    ).toThrow('Denied')
  })
})

describe('exchangeCode', () => {
  it('posts the verifier and maps the token response', async () => {
    const fetchImpl = vi.fn<typeof fetch>(async () =>
      Response.json({ access_token: 'at', refresh_token: 'rt', expires_in: 3600, token_type: 'Bearer' }),
    )

    const tokens = await exchangeCode(config, 'code1', 'verifier1', fetchImpl)

    const [url, init] = fetchImpl.mock.calls[0]!
    expect(url).toBe('http://api.test/oauth/token')
    const body = new URLSearchParams(String(init?.body))
    expect(body.get('grant_type')).toBe('authorization_code')
    expect(body.get('code_verifier')).toBe('verifier1')
    expect(tokens.accessToken).toBe('at')
    expect(tokens.refreshToken).toBe('rt')
  })

  it('throws the server error', async () => {
    const fetchImpl = vi.fn<typeof fetch>(async () =>
      Response.json({ error: 'invalid_grant', error_description: 'Code expired' }, { status: 400 }),
    )

    await expect(exchangeCode(config, 'c', 'v', fetchImpl)).rejects.toMatchObject({ code: 'invalid_grant' })
  })
})
