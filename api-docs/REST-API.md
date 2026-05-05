# Auth Popup — REST API Documentation

> **Plugin:** Auth Popup v1.0.14  
> **Base URL:** `https://your-domain.com/wp-json/auth-popup/v1`  
> **Format:** JSON  
> **Encoding:** UTF-8

---

## Table of Contents

1. [Overview](#1-overview)
2. [Authentication](#2-authentication)
   - [API Key](#21-api-key-required-on-every-request)
   - [Bearer Token](#22-bearer-token-authenticated-endpoints)
   - [Refresh Token](#23-refresh-token)
3. [Standard Response Envelope](#3-standard-response-envelope)
4. [HTTP Status Codes](#4-http-status-codes)
5. [Auth Endpoints](#5-auth-endpoints)
   - [Send OTP](#51-send-otp)
   - [Refresh Token](#52-refresh-token)
   - [Login with Password](#53-login-with-password)
   - [Login with OTP](#54-login-with-otp)
   - [Register](#55-register)
   - [Google OAuth](#56-google-oauth)
   - [Facebook OAuth](#57-facebook-oauth)
   - [Verify OTP (Peek)](#58-verify-otp-peek)
   - [Social Complete](#59-social-complete)
   - [Logout](#510-logout)
   - [Check Phone](#511-check-phone)
   - [Check Email](#512-check-email)
   - [Loyalty Rules](#513-loyalty-rules)
   - [Forgot Password](#514-forgot-password)
   - [Verify Reset OTP](#515-verify-reset-otp)
   - [Reset Password](#516-reset-password)
6. [Address Endpoints](#6-address-endpoints)
   - [List Addresses](#61-list-addresses)
   - [Create Address](#62-create-address)
   - [Get Address](#63-get-address)
   - [Update Address](#64-update-address)
   - [Delete Address](#65-delete-address)
   - [Set Default Address](#66-set-default-address)
7. [Admin Settings](#7-admin-settings)
   - [Get Settings](#71-get-settings)
8. [Flow Diagrams](#8-flow-diagrams)
9. [Bangladesh District Codes](#9-bangladesh-district-codes)

---

## 1. Overview

All endpoints live under `/wp-json/auth-popup/v1/`.

| Group | Path prefix | API Key | Bearer Token | Admin |
|-------|-------------|---------|--------------|-------|
| Authentication | `/auth/` | Required | Not required | No |
| Address book | `/addresses/` | Required | Required | No |
| Admin Settings | `/settings` | Required | Not required | **Yes** |

The `X-API-Key` header is required on **every** request. The app obtains a Bearer token by calling any login endpoint and uses it for address requests.

---

## 2. Authentication

### 2.1 API Key (required on every request)

Every request to the API must include the site's API key in the `X-API-Key` header.  
Obtain the key from **WordPress Admin → Settings → Auth Popup → General → REST API Key**.

```
X-API-Key: <api-key>
```

| Behaviour | Detail |
|-----------|--------|
| Missing key | `403 Forbidden` — `rest_forbidden_api_key` |
| Wrong key | `403 Forbidden` — `rest_forbidden_api_key` |
| No key configured on server | All requests pass (validation skipped) |

> **Keep the API key secret.** Treat it like a password — do not expose it in client-side code, logs, or public repositories.

> **Per-environment keys:** Each WordPress installation generates its own API key on activation. The key on `demo.herlan.shop` will differ from the key on `herlan.com`. Always read the key from **WP Admin → Settings → Auth Popup** on the target server.

---

### 2.2 Bearer Token (authenticated endpoints)

Every login endpoint (`/auth/login`, `/auth/login-otp`, `/auth/register`, `/auth/google`, `/auth/facebook`, `/auth/social-complete`) returns a `token` on success.

**Store this token** in the app's secure storage (e.g. iOS Keychain / Android Keystore).

For all address requests, include the token as a Bearer token in the `Authorization` header:

```
Authorization: Bearer <token>
```

### Token lifetime

- Access tokens are valid for **12 hours** by default (configurable in WP Admin → Settings → Auth Popup → General → Access Token Lifetime).
- When an access token expires, use the **refresh token** to get a new pair — see [§2.3](#23-refresh-token).
- Calling `/auth/logout` immediately invalidates both tokens.
- If a request returns `401` with `"rest_invalid_token"`, the access token has expired — call `/auth/refresh` first.

### Example (authenticated request)

```
GET /wp-json/auth-popup/v1/addresses
X-API-Key: d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9
Authorization: Bearer a3f9c2d1e8b74a6f0c5d2e1f9b8a7c6d5e4f3a2b1c0d9e8f7a6b5c4d3e2f1a0
Content-Type: application/json
```

### Example (public request)

```
POST /wp-json/auth-popup/v1/auth/send-otp
X-API-Key: d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9
Content-Type: application/json
```

> **Always use HTTPS.** Never send keys or tokens over plain HTTP.

---

### 2.3 Refresh Token

Every login response includes a `refresh_token` alongside the short-lived `token`. When the access token expires, exchange the refresh token for a new pair without asking the user to log in again.

**Refresh token lifetime:** 7 days by default (configurable in WP Admin → Settings → Auth Popup → General → Refresh Token Lifetime).

**Token rotation:** each call to `/auth/refresh` **invalidates the old refresh token** and issues a new one. Store the latest `refresh_token` from every refresh response.

#### Flow

```
1. Login  → store token + refresh_token
2. token expires (401 rest_invalid_token on any request)
3. POST /auth/refresh { refresh_token } → new token + new refresh_token
4. Retry original request with new token
5. refresh_token expires (401) → send user to login screen
```

#### Storage recommendations

| Token | Where to store |
|-------|---------------|
| `token` | Memory / secure session (short-lived, replaced often) |
| `refresh_token` | iOS Keychain / Android Keystore / secure storage |

---

## 3. Standard Response Envelope

Every response — success or error — uses this JSON structure:

```json
{
  "success": true,
  "response_code": 200,
  "message": "Human-readable message.",
  "data": {}
}
```

| Field | Type | Description |
|-------|------|-------------|
| `success` | `boolean` | `true` on success, `false` on error |
| `response_code` | `integer` | HTTP status code mirrored in the response body (e.g. `200`, `201`, `401`, `422`) |
| `message` | `string` | Human-readable status message. Display this to the user when relevant. |
| `data` | `object` or `array` | Payload — shape varies per endpoint (documented below). Empty object `{}` on errors. |

> `response_code` always matches the HTTP status code of the response. It is included in the body for clients that cannot easily read HTTP status codes (e.g. some mobile frameworks).

---

## 4. HTTP Status Codes

| Code | Meaning |
|------|---------|
| `200` | OK |
| `201` | Created (new resource) |
| `400` | Bad request |
| `401` | Unauthenticated / wrong credentials / expired token |
| `403` | Forbidden — invalid or missing API key |
| `404` | Endpoint not found — check the URL |
| `405` | Method Not Allowed — endpoint requires a different HTTP method (almost always POST) |
| `409` | Conflict (e.g. phone already registered) |
| `410` | Gone — session or token expired |
| `422` | Validation error (missing or invalid field) |
| `429` | Rate limited — slow down requests |
| `500` | Internal server error |
| `502` | Upstream error (SMS gateway or loyalty API unreachable) |

### Common mistakes

| Mistake | Result |
|---------|--------|
| Calling a POST endpoint with GET | `405 Method Not Allowed` |
| Wrong URL / typo in path | `404 Endpoint not found` |
| Missing `X-API-Key` header | `403 Forbidden` |
| Wrong `X-API-Key` value | `403 Forbidden` |

---

## 5. Auth Endpoints

---

### 5.1 Send OTP


Send a 6-digit OTP via SMS to a phone number.

```
POST /auth/send-otp
Content-Type: application/json
```

#### Request body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `phone` | string | Yes | Mobile number (Bangladeshi format, e.g. `01712345678`) |
| `context` | string | No | `login` (default), `register`, or `social` |

**Context rules:**
- `login` — phone must already be registered; returns `404` if not found
- `social` — phone must **not** be registered yet; returns `409` if taken
- `register` — no existence check; sends the OTP unconditionally

#### Success `200`

```json
{
  "success": true,
  "response_code": 200,
  "message": "OTP sent to 8801712345678",
  "data": {
    "expiry_seconds": 300
  }
}
```

#### Errors

```json
// 422 — invalid phone format
{
  "success": false,
  "response_code": 422,
  "message": "Please enter a valid mobile number.",
  "data": {}
}
```

```json
// 404 — phone not registered (login context)
{
  "success": false,
  "response_code": 404,
  "message": "No account found with this mobile number. Please register first.",
  "data": {}
}
```

```json
// 409 — phone already registered (social context)
{
  "success": false,
  "response_code": 409,
  "message": "This mobile number is already registered. Please use a different number or sign in with your existing account.",
  "data": {}
}
```

```json
// 429 — OTP rate limit hit (max 5 / hour per phone, 10 / hour per IP)
{
  "success": false,
  "response_code": 429,
  "message": "Too many OTP requests. Please try again later.",
  "data": {}
}
```

```json
// 502 — SMS gateway error
{
  "success": false,
  "response_code": 502,
  "message": "Failed to send OTP. Please try again.",
  "data": {}
}
```

---

### 5.2 Refresh Token

Exchange a valid refresh token for a new access token + refresh token pair. The old refresh token is immediately invalidated (rotation).

```
POST /auth/refresh
Content-Type: application/json
```

#### Request body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `refresh_token` | string | Yes | The refresh token received from the last login or refresh response |

#### Success `200`

```json
{
  "success": true,
  "response_code": 200,
  "message": "Token refreshed.",
  "data": {
    "token": "b4e8a1c3f2d6e9b0a7c4d1e8f5b2a9c6d3e0f7b4a1c8e5d2f9b6a3c0e7d4f1b8",
    "refresh_token": "c5f9b2d4e7a0b3c6d9e2f5b8a1c4d7e0f3b6a9c2d5e8f1b4a7c0d3e6f9b2a5c8",
    "expires_in": 43200
  }
}
```

> `expires_in` is in seconds. `43200` = 12 hours (the configured access token lifetime).  
> **Always replace both stored tokens** with the values from this response.

#### Errors

```json
// 401 — refresh token invalid or expired
{
  "success": false,
  "response_code": 401,
  "message": "Invalid or expired refresh token. Please log in again.",
  "data": {}
}
```

---

### 5.3 Login with Password


```
POST /auth/login
Content-Type: application/json
```

#### Request body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `credential` | string | Yes | Email address, username, or mobile number |
| `password` | string | Yes | Account password |
| `redirect_to` | string | No | Optional redirect URL (same domain only) |

#### Success `200`

```json
{
  "success": true,
  "response_code": 200,
  "message": "Login successful!",
  "data": {
    "token": "a3f9c2d1e8b74a6f0c5d2e1f9b8a7c6d5e4f3a2b1c0d9e8f7a6b5c4d3e2f1a0",
    "refresh_token": "c5f9b2d4e7a0b3c6d9e2f5b8a1c4d7e0f3b6a9c2d5e8f1b4a7c0d3e6f9b2a5c8",
    "expires_in": 43200,
    "redirect": "https://your-domain.com"
  }
}
```

> **Save `token`** immediately. Use it in the `Authorization: Bearer` header for all address requests.

#### Errors

```json
// 401 — wrong credentials
{
  "success": false,
  "response_code": 401,
  "message": "The password you entered is incorrect.",
  "data": {}
}
```

```json
// 422 — missing fields
{
  "success": false,
  "response_code": 422,
  "message": "Mobile/email and password are required.",
  "data": {}
}
```

```json
// 429 — brute-force lockout (5 failed attempts / 15 min per account, 10 per IP)
{
  "success": false,
  "response_code": 429,
  "message": "Too many login attempts for this account. Please try again in 15 minutes.",
  "data": {}
}
```

---

### 5.3 Login with OTP

```
POST /auth/login-otp
Content-Type: application/json
```

#### Request body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `phone` | string | Yes | Registered mobile number |
| `otp` | string | Yes | 6-digit OTP received via SMS |
| `redirect_to` | string | No | Optional redirect URL (same domain only) |

#### Success `200`

```json
{
  "success": true,
  "response_code": 200,
  "message": "Login successful!",
  "data": {
    "token": "a3f9c2d1e8b74a6f0c5d2e1f9b8a7c6d5e4f3a2b1c0d9e8f7a6b5c4d3e2f1a0",
    "refresh_token": "c5f9b2d4e7a0b3c6d9e2f5b8a1c4d7e0f3b6a9c2d5e8f1b4a7c0d3e6f9b2a5c8",
    "expires_in": 43200,
    "redirect": "https://your-domain.com"
  }
}
```

#### Errors

```json
// 422 — invalid phone or OTP format
{
  "success": false,
  "response_code": 422,
  "message": "OTP must be exactly 6 digits.",
  "data": {}
}
```

```json
// 401 — incorrect or expired OTP
{
  "success": false,
  "response_code": 401,
  "message": "Incorrect or expired OTP. Please try again.",
  "data": {}
}
```

---

### 5.4 Register

Create a new account. OTP must have been sent first via [Send OTP](#51-send-otp) with `context: register`.

```
POST /auth/register
Content-Type: application/json
```

#### Request body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `phone` | string | Yes | Mobile number |
| `otp` | string | Yes | 6-digit OTP for this phone |
| `name` | string | No | Full name |
| `email` | string | No | Email address |
| `password` | string | No | Password (min 6 chars; auto-generated if omitted) |
| `join_loyalty` | string | No | `"1"` to enrol in Herlan Star Loyalty Programme |
| `gender` | string | No | Required when `join_loyalty=1` (`male` or `female`) |
| `dob` | string | No | Required when `join_loyalty=1` (format: `YYYY-MM-DD`) |
| `card_number` | string | No | Optional loyalty card number |
| `redirect_to` | string | No | Optional redirect URL (same domain only) |

#### Success `201`

```json
{
  "success": true,
  "response_code": 201,
  "message": "Account created! Welcome aboard.",
  "data": {
    "token": "a3f9c2d1e8b74a6f0c5d2e1f9b8a7c6d5e4f3a2b1c0d9e8f7a6b5c4d3e2f1a0",
    "refresh_token": "c5f9b2d4e7a0b3c6d9e2f5b8a1c4d7e0f3b6a9c2d5e8f1b4a7c0d3e6f9b2a5c8",
    "expires_in": 43200,
    "redirect": "https://your-domain.com"
  }
}
```

With loyalty enrolment:

```json
{
  "success": true,
  "response_code": 201,
  "message": "Account created! Welcome aboard. You have joined the Herlan Star Loyalty Programme!",
  "data": {
    "token": "a3f9c2d1e8b74a6f0c5d2e1f9b8a7c6d5e4f3a2b1c0d9e8f7a6b5c4d3e2f1a0",
    "refresh_token": "c5f9b2d4e7a0b3c6d9e2f5b8a1c4d7e0f3b6a9c2d5e8f1b4a7c0d3e6f9b2a5c8",
    "expires_in": 43200,
    "redirect": "https://your-domain.com"
  }
}
```

#### Errors

```json
// 422 — phone or email already taken
{
  "success": false,
  "response_code": 422,
  "message": "An account with this phone number already exists.",
  "data": {}
}
```

```json
// 401 — wrong OTP
{
  "success": false,
  "response_code": 401,
  "message": "Incorrect or expired OTP. Please try again.",
  "data": {}
}
```

---

### 5.5 Google OAuth

Authenticate with a Google access token obtained from the Google Sign-In SDK.

- If the account already has a verified phone → **logged in immediately**, token returned.
- If not → a mobile verification step is required (see [Social Complete](#58-social-complete)).

```
POST /auth/google
Content-Type: application/json
```

#### Request body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `access_token` | string | Yes | Google access token from Google Sign-In SDK |
| `redirect_to` | string | No | Optional redirect URL (same domain only) |

#### Success `200` — direct login

```json
{
  "success": true,
  "response_code": 200,
  "message": "Logged in with Google!",
  "data": {
    "token": "a3f9c2d1e8b74a6f0c5d2e1f9b8a7c6d5e4f3a2b1c0d9e8f7a6b5c4d3e2f1a0",
    "refresh_token": "c5f9b2d4e7a0b3c6d9e2f5b8a1c4d7e0f3b6a9c2d5e8f1b4a7c0d3e6f9b2a5c8",
    "expires_in": 43200,
    "redirect": "https://your-domain.com"
  }
}
```

#### Success `200` — phone verification required

```json
{
  "success": true,
  "response_code": 200,
  "message": "Please verify your mobile number to complete sign-in.",
  "data": {
    "need_mobile": true,
    "temp_token": "aBcDeFgHiJkLmNoPqRsTuVwXyZ123456",
    "provider": "google",
    "name": "John Doe"
  }
}
```

> When `need_mobile` is `true`: call [Send OTP](#51-send-otp) with `context: social`, then call [Social Complete](#58-social-complete) with the `temp_token`.  
> `temp_token` expires in **15 minutes**.

#### Errors

```json
// 401 — invalid or expired Google token
{
  "success": false,
  "response_code": 401,
  "message": "Invalid Google token.",
  "data": {}
}
```

---

### 5.6 Facebook OAuth

Authenticate with a Facebook access token. Behaviour is identical to [Google OAuth](#55-google-oauth).

```
POST /auth/facebook
Content-Type: application/json
```

#### Request body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `access_token` | string | Yes | Facebook access token from Facebook Login SDK |
| `redirect_to` | string | No | Optional redirect URL (same domain only) |

#### Success `200` — direct login

```json
{
  "success": true,
  "response_code": 200,
  "message": "Logged in with Facebook!",
  "data": {
    "token": "a3f9c2d1e8b74a6f0c5d2e1f9b8a7c6d5e4f3a2b1c0d9e8f7a6b5c4d3e2f1a0",
    "refresh_token": "c5f9b2d4e7a0b3c6d9e2f5b8a1c4d7e0f3b6a9c2d5e8f1b4a7c0d3e6f9b2a5c8",
    "expires_in": 43200,
    "redirect": "https://your-domain.com"
  }
}
```

#### Success `200` — phone verification required

```json
{
  "success": true,
  "response_code": 200,
  "message": "Please verify your mobile number to complete sign-in.",
  "data": {
    "need_mobile": true,
    "temp_token": "aBcDeFgHiJkLmNoPqRsTuVwXyZ123456",
    "provider": "facebook",
    "name": "Jane Doe"
  }
}
```

#### Errors

```json
// 401 — invalid token
{
  "success": false,
  "response_code": 401,
  "message": "Invalid Facebook token.",
  "data": {}
}
```

---

### 5.7 Verify OTP (Peek)

Validate an OTP **without consuming it**. Use this to confirm the OTP before showing a next-step form. The OTP remains valid for a subsequent login or register call.

```
POST /auth/verify-otp
Content-Type: application/json
```

#### Request body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `phone` | string | Yes | Phone number the OTP was sent to |
| `otp` | string | Yes | 6-digit OTP to check |

#### Success `200`

```json
{
  "success": true,
  "response_code": 200,
  "message": "OTP verified.",
  "data": {}
}
```

#### Errors

```json
// 401 — wrong or expired OTP
{
  "success": false,
  "response_code": 401,
  "message": "Incorrect or expired OTP. Please try again.",
  "data": {}
}
```

---

### 5.8 Social Complete

Finish a social login by verifying the user's phone number with an OTP.

**Flow before calling this:**
1. Receive `need_mobile: true` from `/auth/google` or `/auth/facebook`
2. Call [Send OTP](#51-send-otp) with `context: social` to send an OTP to the phone
3. Call this endpoint with the `temp_token`, phone, and OTP

```
POST /auth/social-complete
Content-Type: application/json
```

#### Request body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `temp_token` | string | Yes | Token from the Google/Facebook response |
| `phone` | string | Yes | Mobile number to verify |
| `otp` | string | Yes | 6-digit OTP sent to the phone |
| `join_loyalty` | string | No | `"1"` to enrol in loyalty programme |
| `gender` | string | No | Required when `join_loyalty=1` |
| `dob` | string | No | Required when `join_loyalty=1` (format: `YYYY-MM-DD`) |
| `card_number` | string | No | Optional loyalty card number |
| `redirect_to` | string | No | Optional redirect URL (same domain only) |

#### Success `200`

```json
{
  "success": true,
  "response_code": 200,
  "message": "Signed in successfully!",
  "data": {
    "token": "a3f9c2d1e8b74a6f0c5d2e1f9b8a7c6d5e4f3a2b1c0d9e8f7a6b5c4d3e2f1a0",
    "refresh_token": "c5f9b2d4e7a0b3c6d9e2f5b8a1c4d7e0f3b6a9c2d5e8f1b4a7c0d3e6f9b2a5c8",
    "expires_in": 43200,
    "redirect": "https://your-domain.com"
  }
}
```

#### Errors

```json
// 410 — temp_token expired (> 15 minutes old)
{
  "success": false,
  "response_code": 410,
  "message": "Session expired. Please try signing in again.",
  "data": {}
}
```

```json
// 401 — wrong OTP
{
  "success": false,
  "response_code": 401,
  "message": "Incorrect or expired OTP. Please try again.",
  "data": {}
}
```

---

### 5.9 Logout

Invalidate the current Bearer token and destroy the session.

```
POST /auth/logout
Authorization: Bearer <token>
Content-Type: application/json
```

#### Request body

None required.

#### Success `200`

```json
{
  "success": true,
  "response_code": 200,
  "message": "Logged out successfully.",
  "data": {
    "redirect": "https://your-domain.com"
  }
}
```

> After receiving this response, **delete the stored token** from the app.

---

### 5.10 Check Phone

Check whether a phone number is already registered. Use this during registration to give real-time feedback before sending an OTP.

```
GET /auth/check-phone?phone=01712345678
```

#### Query parameters

| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `phone` | string | Yes | Phone number to check |

#### Success `200` — valid, registered

```json
{
  "success": true,
  "response_code": 200,
  "message": "Phone number checked.",
  "data": {
    "valid": true,
    "exists": true
  }
}
```

#### Success `200` — valid, not registered

```json
{
  "success": true,
  "response_code": 200,
  "message": "Phone number checked.",
  "data": {
    "valid": true,
    "exists": false
  }
}
```

#### Success `200` — invalid format

```json
{
  "success": true,
  "response_code": 200,
  "message": "Phone number checked.",
  "data": {
    "valid": false,
    "exists": false
  }
}
```

---

### 5.11 Check Email

Check whether an email address is already registered. Use this during registration to give real-time feedback before the user proceeds.

```
GET /auth/check-email?email=user@example.com
```

#### Query parameters

| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `email` | string | Yes | Email address to check |

#### Success `200` — valid format, email already registered

```json
{
  "success": true,
  "response_code": 200,
  "message": "Email address checked.",
  "data": {
    "valid": true,
    "exists": true
  }
}
```

#### Success `200` — valid format, email available

```json
{
  "success": true,
  "response_code": 200,
  "message": "Email address checked.",
  "data": {
    "valid": true,
    "exists": false
  }
}
```

#### Success `200` — invalid email format

```json
{
  "success": true,
  "response_code": 200,
  "message": "Email address checked.",
  "data": {
    "valid": false,
    "exists": false
  }
}
```

#### Error `400` — missing `email` parameter

```json
{
  "success": false,
  "response_code": 400,
  "message": "Missing parameter(s): email",
  "data": {}
}
```

#### Logic table

| `valid` | `exists` | Meaning | Recommended action |
|---------|----------|---------|-------------------|
| `false` | `false` | Email string is not a valid address | Show inline format error |
| `true` | `false` | Valid email, not yet registered | Allow registration to proceed |
| `true` | `true` | Email already has an account | Block step, prompt user to log in |

> **Note:** `email` is optional during registration. Only call this endpoint when the user has actually typed an email address. If the field is left blank, no check is needed and registration can proceed without it.

---

### 5.12 Loyalty Rules

Fetch Herlan Star Loyalty Programme rules. Results are cached for 5 minutes server-side.

```
GET /auth/loyalty-rules
```

#### Success `200`

```json
{
  "success": true,
  "response_code": 200,
  "message": "Loyalty rules retrieved.",
  "data": {
    "rules": [
      {
        "name": "Welcome Bonus",
        "description": "Earn 100 points on your first purchase."
      },
      {
        "name": "Birthday Reward",
        "description": "Double points on your birthday month."
      }
    ]
  }
}
```

#### Errors

```json
// 502 — loyalty API unreachable
{
  "success": false,
  "response_code": 502,
  "message": "Failed to load loyalty rules.",
  "data": {}
}
```

---

### 5.13 Forgot Password

Send a 6-digit OTP to an email address to begin the password reset flow.

- OTP expires in **10 minutes**
- 60-second cooldown between resend requests

```
POST /auth/forgot-password
Content-Type: application/json
```

#### Request body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `email` | string | Yes | Email address of the account |

#### Success `200`

```json
{
  "success": true,
  "response_code": 200,
  "message": "OTP sent to your email address.",
  "data": {
    "expiry_seconds": 600
  }
}
```

#### Errors

```json
// 404 — no account with this email
{
  "success": false,
  "response_code": 404,
  "message": "No account found with this email address.",
  "data": {}
}
```

```json
// 429 — resend cooldown active
{
  "success": false,
  "response_code": 429,
  "message": "Please wait a moment before requesting another OTP.",
  "data": {}
}
```

```json
// 502 — mail server error
{
  "success": false,
  "response_code": 502,
  "message": "Mail server returned an error. Please contact support.",
  "data": {}
}
```

---

### 5.14 Verify Reset OTP

Verify the OTP received by email. On success returns a `reset_token` valid for **15 minutes**.  
Maximum **5 incorrect attempts** before the OTP is invalidated.

```
POST /auth/verify-reset-otp
Content-Type: application/json
```

#### Request body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `email` | string | Yes | Email the OTP was sent to |
| `otp` | string | Yes | 6-digit OTP from the email |

#### Success `200`

```json
{
  "success": true,
  "response_code": 200,
  "message": "OTP verified. Please set your new password.",
  "data": {
    "reset_token": "aBcDeFgHiJkLmNoPqRsTuVwXyZ123456"
  }
}
```

> Pass `reset_token` to [Reset Password](#514-reset-password).

#### Errors

```json
// 401 — incorrect OTP
{
  "success": false,
  "response_code": 401,
  "message": "Incorrect OTP. Please try again.",
  "data": {}
}
```

```json
// 429 — too many incorrect attempts, OTP invalidated
{
  "success": false,
  "response_code": 429,
  "message": "Too many incorrect attempts. Please request a new OTP.",
  "data": {}
}
```

```json
// 410 — OTP expired
{
  "success": false,
  "response_code": 410,
  "message": "OTP has expired. Please request a new one.",
  "data": {}
}
```

---

### 5.15 Reset Password

Set a new password using the `reset_token` from [Verify Reset OTP](#513-verify-reset-otp).

```
POST /auth/reset-password
Content-Type: application/json
```

#### Request body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `reset_token` | string | Yes | Token from verify-reset-otp response |
| `new_password` | string | Yes | New password (minimum 6 characters) |
| `confirm_password` | string | Yes | Must exactly match `new_password` |

#### Success `200`

```json
{
  "success": true,
  "response_code": 200,
  "message": "Password reset successfully! You can now log in with your new password.",
  "data": {}
}
```

#### Errors

```json
// 410 — reset_token expired (> 15 minutes)
{
  "success": false,
  "response_code": 410,
  "message": "Session expired. Please start over.",
  "data": {}
}
```

```json
// 422 — password too short
{
  "success": false,
  "response_code": 422,
  "message": "Password must be at least 6 characters.",
  "data": {}
}
```

```json
// 422 — passwords do not match
{
  "success": false,
  "response_code": 422,
  "message": "Passwords do not match.",
  "data": {}
}
```

---

## 6. Address Endpoints

All address endpoints require a valid Bearer token in the `Authorization` header.

```
Authorization: Bearer <token>
```

A `401` is returned if the token is missing, invalid, or expired.

---

### 6.1 List Addresses

```
GET /addresses
Authorization: Bearer <token>
```

#### Success `200`

```json
{
  "success": true,
  "response_code": 200,
  "message": "Addresses retrieved.",
  "data": [
    {
      "id": "1",
      "user_id": "42",
      "label": "Home",
      "first_name": "John",
      "last_name": "Doe",
      "company": "",
      "address_1": "123 Main Road",
      "address_2": "Apartment 4B",
      "city": "Dhaka",
      "state": "BD-06",
      "postcode": "1200",
      "country": "BD",
      "phone": "01712345678",
      "is_default": "1",
      "created_at": "2026-01-15 10:30:00"
    },
    {
      "id": "2",
      "user_id": "42",
      "label": "Office",
      "first_name": "John",
      "last_name": "Doe",
      "company": "Acme Ltd",
      "address_1": "456 Business Avenue",
      "address_2": "",
      "city": "Chittagong",
      "state": "BD-04",
      "postcode": "4100",
      "country": "BD",
      "phone": "01812345678",
      "is_default": "0",
      "created_at": "2026-02-20 14:15:00"
    }
  ]
}
```

Empty address book:

```json
{
  "success": true,
  "response_code": 200,
  "message": "Addresses retrieved.",
  "data": []
}
```

#### Errors

```json
// 401 — missing or invalid token
{
  "success": false,
  "response_code": 401,
  "message": "Invalid or expired API token. Please log in again.",
  "data": {}
}
```

---

### 6.2 Create Address

```
POST /addresses
Authorization: Bearer <token>
Content-Type: application/json
```

#### Request body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `first_name` | string | **Yes** | First name |
| `phone` | string | **Yes** | Contact phone (Bangladeshi format) |
| `address_1` | string | **Yes** | Street address line 1 |
| `state` | string | **Yes** | Bangladesh district code (e.g. `BD-06`) — see §9 |
| `label` | string | No | Nickname, e.g. `Home`, `Office` |
| `last_name` | string | No | Last name |
| `company` | string | No | Company name |
| `address_2` | string | No | Street address line 2 |
| `city` | string | No | City |
| `postcode` | string | No | Postal code |
| `country` | string | No | ISO country code (default: `BD`) |

> The first address created is automatically set as the default.

#### Success `201`

```json
{
  "success": true,
  "response_code": 201,
  "message": "Address saved.",
  "data": {
    "address_id": 3,
    "addresses": [
      {
        "id": "1",
        "user_id": "42",
        "label": "Home",
        "first_name": "John",
        "last_name": "Doe",
        "company": "",
        "address_1": "123 Main Road",
        "address_2": "",
        "city": "Dhaka",
        "state": "BD-06",
        "postcode": "1200",
        "country": "BD",
        "phone": "01712345678",
        "is_default": "1",
        "created_at": "2026-01-15 10:30:00"
      },
      {
        "id": "3",
        "user_id": "42",
        "label": "Office",
        "first_name": "John",
        "last_name": "Doe",
        "company": "Acme Ltd",
        "address_1": "456 Business Avenue",
        "address_2": "",
        "city": "Chittagong",
        "state": "BD-04",
        "postcode": "4100",
        "country": "BD",
        "phone": "01812345678",
        "is_default": "0",
        "created_at": "2026-05-03 09:00:00"
      }
    ]
  }
}
```

#### Errors

```json
// 422 — missing required fields
{
  "success": false,
  "response_code": 422,
  "message": "Required fields missing: phone, address_1",
  "data": {}
}
```

```json
// 422 — invalid phone
{
  "success": false,
  "response_code": 422,
  "message": "Please enter a valid Bangladeshi mobile number (e.g. 01712345678).",
  "data": {}
}
```

```json
// 422 — invalid district code
{
  "success": false,
  "response_code": 422,
  "message": "Please select a valid district.",
  "data": {}
}
```

---

### 6.3 Get Address

```
GET /addresses/{id}
Authorization: Bearer <token>
```

#### Path parameters

| Param | Type | Description |
|-------|------|-------------|
| `id` | integer | Address ID |

#### Success `200`

```json
{
  "success": true,
  "response_code": 200,
  "message": "Address retrieved.",
  "data": {
    "id": "1",
    "user_id": "42",
    "label": "Home",
    "first_name": "John",
    "last_name": "Doe",
    "company": "",
    "address_1": "123 Main Road",
    "address_2": "Apartment 4B",
    "city": "Dhaka",
    "state": "BD-06",
    "postcode": "1200",
    "country": "BD",
    "phone": "01712345678",
    "is_default": "1",
    "created_at": "2026-01-15 10:30:00"
  }
}
```

#### Errors

```json
// 404 — address not found or belongs to another user
{
  "success": false,
  "response_code": 404,
  "message": "Address not found.",
  "data": {}
}
```

---

### 6.4 Update Address

Full replacement of an address. All fields follow the same rules as [Create Address](#62-create-address).

```
PUT /addresses/{id}
Authorization: Bearer <token>
Content-Type: application/json
```

#### Path parameters

| Param | Type | Description |
|-------|------|-------------|
| `id` | integer | Address ID to update |

#### Request body

Same fields as Create Address. Required fields (`first_name`, `phone`, `address_1`, `state`) must still be present.

#### Success `200`

```json
{
  "success": true,
  "response_code": 200,
  "message": "Address updated.",
  "data": {
    "address_id": 1,
    "addresses": [
      {
        "id": "1",
        "user_id": "42",
        "label": "Home",
        "first_name": "John",
        "last_name": "Doe",
        "company": "",
        "address_1": "789 New Street",
        "address_2": "",
        "city": "Dhaka",
        "state": "BD-06",
        "postcode": "1200",
        "country": "BD",
        "phone": "01712345678",
        "is_default": "1",
        "created_at": "2026-01-15 10:30:00"
      }
    ]
  }
}
```

#### Errors

```json
// 404 — address not found
{
  "success": false,
  "response_code": 404,
  "message": "Address not found.",
  "data": {}
}
```

---

### 6.5 Delete Address

If the deleted address was the default, the next oldest address is promoted automatically.

```
DELETE /addresses/{id}
Authorization: Bearer <token>
```

#### Path parameters

| Param | Type | Description |
|-------|------|-------------|
| `id` | integer | Address ID to delete |

#### Success `200`

Returns the updated address list.

```json
{
  "success": true,
  "response_code": 200,
  "message": "Address deleted.",
  "data": [
    {
      "id": "2",
      "user_id": "42",
      "label": "Office",
      "first_name": "John",
      "last_name": "Doe",
      "company": "Acme Ltd",
      "address_1": "456 Business Avenue",
      "address_2": "",
      "city": "Chittagong",
      "state": "BD-04",
      "postcode": "4100",
      "country": "BD",
      "phone": "01812345678",
      "is_default": "1",
      "created_at": "2026-02-20 14:15:00"
    }
  ]
}
```

#### Errors

```json
// 404 — address not found
{
  "success": false,
  "response_code": 404,
  "message": "Address not found.",
  "data": {}
}
```

---

### 6.6 Set Default Address

Mark an address as the default. The new default is also synced to WooCommerce billing/shipping fields.

```
POST /addresses/{id}/default
Authorization: Bearer <token>
```

#### Path parameters

| Param | Type | Description |
|-------|------|-------------|
| `id` | integer | Address ID to promote |

#### Success `200`

Returns the full updated list, default address first.

```json
{
  "success": true,
  "response_code": 200,
  "message": "Default address updated.",
  "data": [
    {
      "id": "2",
      "user_id": "42",
      "label": "Office",
      "first_name": "John",
      "last_name": "Doe",
      "company": "Acme Ltd",
      "address_1": "456 Business Avenue",
      "address_2": "",
      "city": "Chittagong",
      "state": "BD-04",
      "postcode": "4100",
      "country": "BD",
      "phone": "01812345678",
      "is_default": "1",
      "created_at": "2026-02-20 14:15:00"
    },
    {
      "id": "1",
      "user_id": "42",
      "label": "Home",
      "first_name": "John",
      "last_name": "Doe",
      "company": "",
      "address_1": "123 Main Road",
      "address_2": "Apartment 4B",
      "city": "Dhaka",
      "state": "BD-06",
      "postcode": "1200",
      "country": "BD",
      "phone": "01712345678",
      "is_default": "0",
      "created_at": "2026-01-15 10:30:00"
    }
  ]
}
```

#### Errors

```json
// 404 — address not found
{
  "success": false,
  "response_code": 404,
  "message": "Address not found.",
  "data": {}
}
```

---

## 7. Admin Settings

---

### 7.1 Get Settings

Retrieve all plugin configuration settings. Returns the complete `auth_popup_settings` option merged with defaults.

> **Admin only.** The caller must be authenticated as a WordPress administrator (`manage_options` capability). Use WordPress cookie auth or a [WP Application Password](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/) — the plugin's Bearer token is **not** accepted here because it represents a regular user, not an admin.

```
GET /settings
X-API-Key: <api-key>
Authorization: Basic <base64(username:application-password)>
```

#### Success `200`

```json
{
  "success": true,
  "response_code": 200,
  "message": "Settings retrieved.",
  "data": {
    "sms_api_token": "sk-live-xxxxxxxxxxxx",
    "sms_sender_id": "HERLAN",
    "sms_base_url": "https://se.smsplus.net/api/v1",
    "google_client_id": "123456789-abc.apps.googleusercontent.com",
    "google_client_secret": "GOCSPX-xxxxxxxxxxxx",
    "fb_app_id": "9876543210",
    "fb_app_secret": "xxxxxxxxxxxxxxxxxxxx",
    "loyalty_enabled": "1",
    "loyalty_api_url": "https://loyalty.herlan.store/api/customer",
    "redirect_url": "https://your-domain.com",
    "trigger_selector": ".auth-popup-trigger",
    "otp_expiry_minutes": 5,
    "otp_max_per_hour": 5,
    "otp_max_per_hour_ip": 10,
    "otp_max_verify_attempts": 5,
    "enable_password_login": "1",
    "enable_otp_login": "1",
    "enable_google": "1",
    "enable_facebook": "1",
    "popup_logo_url": "https://your-domain.com/logo.png",
    "popup_brand_name": "Herlan",
    "checkout_hide_shipping_form": "1",
    "checkout_disable_ship_to_different": "1",
    "myaccount_inline_form": "1",
    "rest_api_key": "d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9",
    "token_lifetime_hours": 12,
    "refresh_token_lifetime_days": 7
  }
}
```

#### Settings reference

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `sms_api_token` | string | `""` | Bearer token for SSLCommerce iSMS Plus |
| `sms_sender_id` | string | `""` | Approved SMS sender ID / mask |
| `sms_base_url` | string | `"https://se.smsplus.net/api/v1"` | SSLCommerce API base URL |
| `google_client_id` | string | `""` | Google OAuth client ID |
| `google_client_secret` | string | `""` | Google OAuth client secret |
| `fb_app_id` | string | `""` | Facebook App ID |
| `fb_app_secret` | string | `""` | Facebook App Secret |
| `loyalty_enabled` | string | `"1"` | `"1"` = loyalty programme enabled |
| `loyalty_api_url` | string | `""` | Herlan Loyalty API base URL |
| `redirect_url` | string | site home | Post-login redirect URL |
| `trigger_selector` | string | `".auth-popup-trigger"` | CSS selector that opens the popup |
| `otp_expiry_minutes` | integer | `5` | OTP validity (1–30 min) |
| `otp_max_per_hour` | integer | `5` | Max OTP sends per phone per hour |
| `otp_max_per_hour_ip` | integer | `10` | Max OTP sends per IP per hour |
| `otp_max_verify_attempts` | integer | `5` | Max wrong OTP guesses before invalidation |
| `enable_password_login` | string | `"1"` | `"1"` = password login enabled |
| `enable_otp_login` | string | `"1"` | `"1"` = OTP login enabled |
| `enable_google` | string | `"1"` | `"1"` = Google login button shown |
| `enable_facebook` | string | `"1"` | `"1"` = Facebook login button shown |
| `popup_logo_url` | string | `""` | Logo URL displayed in the popup |
| `popup_brand_name` | string | site name | Brand name in the popup header |
| `checkout_hide_shipping_form` | string | `"1"` | Auto-hide shipping form when addresses exist |
| `checkout_disable_ship_to_different` | string | `"1"` | Hide "Ship to different address" option |
| `myaccount_inline_form` | string | `"1"` | Use inline form on `/my-account` |
| `rest_api_key` | string | auto-generated | The `X-API-Key` value for this installation |
| `token_lifetime_hours` | integer | `12` | Access token lifetime in hours (1–168) |
| `refresh_token_lifetime_days` | integer | `7` | Refresh token lifetime in days (1–365) |

> Boolean-style settings use string `"1"` (enabled) or `"0"` (disabled), not JSON booleans.

#### Errors

```json
// 401 — not authenticated
{
  "success": false,
  "response_code": 401,
  "message": "Authentication required. Please log in to access this resource.",
  "data": {}
}
```

```json
// 403 — authenticated but not an admin
{
  "success": false,
  "response_code": 403,
  "message": "You do not have permission to access this resource.",
  "data": {}
}
```

```json
// 403 — missing or invalid X-API-Key (when a key is configured on the server)
{
  "success": false,
  "response_code": 403,
  "message": "Invalid or missing API key.",
  "data": {}
}
```

---

## 8. Flow Diagrams

### Phone OTP Login

```
App                             API
 |                               |
 |-- POST /auth/send-otp ------->|  X-API-Key: <key>  phone, context:"login"
 |<-- 200 { expiry_seconds } ----|
 |                               |
 |   [user reads SMS code]       |
 |                               |
 |-- POST /auth/login-otp ------>|  X-API-Key: <key>  phone, otp
 |<-- 200 { token,               |
 |    refresh_token,             |
 |    expires_in, redirect } ----|
 |                               |
 |   [store both tokens]         |
 |                               |
 |-- GET  /addresses ----------->|  X-API-Key: <key>  Authorization: Bearer <token>
 |<-- 200 { [...addresses] } ----|
 |                               |
 |   [token expires → 401]       |
 |                               |
 |-- POST /auth/refresh -------->|  X-API-Key: <key>  refresh_token
 |<-- 200 { token,               |
 |    refresh_token,             |
 |    expires_in } --------------|
 |                               |
 |   [replace stored tokens]     |
 |   [retry original request]    |
```

---

### Phone OTP Registration

```
App                             API
 |                               |
 |-- GET /auth/check-phone ----->|  ?phone=01712345678
 |<-- 200 { valid:true,          |
 |           exists:false } -----|  ← safe to register
 |                               |
 |-- GET /auth/check-email ----->|  ?email=user@example.com  (only if user typed email)
 |<-- 200 { valid:true,          |
 |           exists:false } -----|  ← email available
 |                               |
 |-- POST /auth/send-otp ------->|  phone, context:"register"
 |<-- 200 { expiry_seconds } ----|
 |                               |
 |   [user reads SMS code]       |
 |                               |
 |-- POST /auth/verify-otp ----->|  phone, otp  (optional peek)
 |<-- 200 "OTP verified." -------|
 |                               |
 |   [user fills in details]     |
 |                               |
 |-- POST /auth/register ------->|  phone, otp, name, email, password
 |<-- 201 { token, redirect } ---|
 |                               |
 |   [store token securely]      |
```

---

### Google / Facebook OAuth — New User

```
App                             API
 |                               |
 |-- POST /auth/google --------->|  access_token
 |<-- 200 { need_mobile:true,    |
 |    temp_token, provider } ----|
 |                               |
 |-- POST /auth/send-otp ------->|  phone, context:"social"
 |<-- 200 { expiry_seconds } ----|
 |                               |
 |   [user reads SMS code]       |
 |                               |
 |-- POST /auth/social-complete->|  temp_token, phone, otp
 |<-- 200 { token, redirect } ---|
 |                               |
 |   [store token securely]      |
```

---

### Forgot Password

```
App                             API
 |                               |
 |-- POST /auth/forgot-password->|  email
 |<-- 200 { expiry_seconds } ----|
 |                               |
 |   [user checks email]         |
 |                               |
 |-- POST /auth/verify-reset-otp>|  email, otp
 |<-- 200 { reset_token } -------|
 |                               |
 |   [user types new password]   |
 |                               |
 |-- POST /auth/reset-password ->|  reset_token, new_password, confirm_password
 |<-- 200 "Password reset." -----|
 |                               |
 |   [send user to login screen] |
```

---

### Logout

```
App                             API
 |                               |
 |-- POST /auth/logout --------->|  Authorization: Bearer <token>
 |<-- 200 "Logged out." ---------|
 |                               |
 |   [delete stored token]       |
```

---

## 9. Bangladesh District Codes

Use these for the `state` field in address endpoints.

| Code | District | Code | District |
|------|----------|------|----------|
| BD-01 | Bagerhat | BD-33 | Lakshmipur |
| BD-02 | Bandarban | BD-34 | Lalmonirhat |
| BD-03 | Barguna | BD-35 | Madaripur |
| BD-04 | Chittagong | BD-36 | Magura |
| BD-05 | Barisal | BD-37 | Manikganj |
| BD-06 | Dhaka | BD-38 | Meherpur |
| BD-07 | Bhola | BD-39 | Moulvibazar |
| BD-08 | Bogra | BD-40 | Munshiganj |
| BD-09 | Brahmanbaria | BD-41 | Mymensingh |
| BD-10 | Chandpur | BD-42 | Naogaon |
| BD-11 | Chapai Nawabganj | BD-43 | Narail |
| BD-12 | Chuadanga | BD-44 | Narayanganj |
| BD-13 | Comilla | BD-45 | Narsingdi |
| BD-14 | Cox's Bazar | BD-46 | Natore |
| BD-15 | Dhaka (Savar) | BD-47 | Netrakona |
| BD-16 | Dinajpur | BD-48 | Nilphamari |
| BD-17 | Faridpur | BD-49 | Noakhali |
| BD-18 | Feni | BD-50 | Pabna |
| BD-19 | Gaibandha | BD-51 | Panchagarh |
| BD-20 | Gazipur | BD-52 | Patuakhali |
| BD-21 | Gopalganj | BD-53 | Pirojpur |
| BD-22 | Habiganj | BD-54 | Rajbari |
| BD-23 | Jamalpur | BD-55 | Rajshahi |
| BD-24 | Jessore | BD-56 | Rangamati |
| BD-25 | Jhalokati | BD-57 | Rangpur |
| BD-26 | Jhenaidah | BD-58 | Satkhira |
| BD-27 | Joypurhat | BD-59 | Shariatpur |
| BD-28 | Khagrachhari | BD-60 | Sherpur |
| BD-29 | Khulna | BD-61 | Sirajganj |
| BD-30 | Kishoreganj | BD-62 | Sunamganj |
| BD-31 | Kurigram | BD-63 | Sylhet |
| BD-32 | Kushtia | BD-64 | Tangail |

---

*Documentation generated for Auth Popup v1.0.14 — 2026-05-05 (admin settings endpoint added)*
