# Config fixtures

On the VIP platform, runtime configuration is injected as a single PHP
constant (`VIP_SHAREADRAFT_CONFIG`) holding a plain associative array,
defined **before** the plugin is loaded. These fixtures mock that constant for
local development and automated testing.

Nothing in the constant is required. Defining it is how the platform says this
integration is enabled for the site, and every value it can carry falls back to
a built-in default — so "incomplete" here means a value that arrived unusable,
not a missing one.

| Fixture | State it simulates |
| --- | --- |
| `config-valid.php` | Fully configured: every offered value filled in, and deliberately not the built-in default so it is visible when it is used. |
| `config-minimal.php` | The constant defined and empty — the ordinary state. Every value falls back to its default. |
| `config-incomplete.php` | Setup in progress: a field opened in the Dashboard but left blank, arriving as an empty string. Must fall back, not be taken at face value. |
| `config-invalid.php` | Constant holds a non-array value. Exercises the `is_array()` guard. |
| `config-local.php` | Optional, **git-ignored** local override — see below. |

A fifth state, the constant never being defined at all, needs no fixture:
`ConfigTest` covers it by constructing `Config` with `null`.

## Local overrides (`config-local.php`)

To test with values you don't want committed (real-ish tokens, a staging API
URL, experiments), copy any fixture to `fixtures/config-local.php` and edit it:

```sh
cp fixtures/config-valid.php fixtures/config-local.php
```

When it exists, the dev-env plugin loader uses it instead of
`config-valid.php`. It is listed in `.gitignore`, so it never ends up in a
commit.

## Where they are used

- **Local development:** [`.wpvip/plugin-loader.php`](../.wpvip/plugin-loader.php)
  defines the constant from `config-local.php` when present, otherwise
  `config-valid.php`, before loading the plugin — mirroring how VIP injects
  config in production. Swap the fixture there to observe other states.
- **Integration tests:** [`tests/integration/bootstrap.php`](../tests/integration/bootstrap.php)
  defines the constant from `config-valid.php`; `ConfigTest` constructs
  `Config` directly to cover every state.

Never put real production secrets in fixtures — including `config-local.php`;
git-ignored is not encrypted.
