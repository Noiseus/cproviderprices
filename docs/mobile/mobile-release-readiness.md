# Mobile release readiness

## Current foundation

The current Expo app has development identifiers only:

- iOS bundle identifier: `com.cproviderprices.mobile`
- Android package: `com.cproviderprices.mobile`
- Deep-link scheme: `cproviderprices`

They are configuration placeholders, not registered store records, and this repository contains no signing keys, provisioning profiles, store credentials, icons, screenshots, or submitted binaries.

## Local validation

From the repository root, run:

```text
npm run mobile:lint
npm run mobile:typecheck
npm run mobile:test
npm run mobile:config
npm run mobile:export
```

The export check validates Metro's Android bundle path without requiring EAS, an Android SDK, or store access. Before a native build phase, run an Expo prebuild in a disposable worktree/environment and review generated native changes; do not commit generated credentials. CI runs the same lint, strict typecheck, tests, public config validation, and export check when mobile/shared-client changes are detected.

## Required before distribution

1. Register production identifiers and verified universal-link/app-link domains.
2. Supply reviewed app icon, splash assets, accessibility review, privacy policy, and EN/ES store metadata.
3. Create separate staging and production API origins, signed build credentials, release channels, and rollback/incident instructions outside the repository.
4. Complete physical iOS/Android device testing for SecureStore, token expiry/revocation, network interruption, timezone formatting, tablet layouts, deep links, and account/logout flow.
5. Complete privacy disclosures and data-safety declarations, including what native diagnostics may transmit.

## Deferred intentionally

No App Store or Play Store publishing, push notifications, universal links, file-picker imports, background uploads, offline-first synchronization, local database cache, or full native editing feature parity is included. Likely future notification cases are import completion/failure and stale supplier data; they require a separate consent, token, and delivery design.
