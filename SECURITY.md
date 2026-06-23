# Security Policy

## Supported versions

Security fixes are applied to the latest released minor version. Until `1.0.0`,
please always test against the most recent `0.x` release.

## Reporting a vulnerability

**Please do not report security vulnerabilities through public GitHub issues,
discussions, or pull requests.**

Instead, email **happones@hotmail.com** with:

- A description of the vulnerability and its impact.
- Steps to reproduce (proof of concept if possible).
- Affected version(s).

You will receive an acknowledgement within a few business days. Once the issue is
confirmed and a fix is prepared, we will coordinate a disclosure timeline with you
and credit you in the release notes (unless you prefer to remain anonymous).

## Scope notes

Kinetix signs and encrypts the parameters it round-trips through the browser
(table model/column lists, upload targets, export/import tokens) with Laravel's
`Crypt`, and authorizes actions server-side. If you find a way to tamper with any
of these to read or write data the current user shouldn't access, that is in scope.
