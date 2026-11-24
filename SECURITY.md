# Security

## Reporting a vulnerability

Report it privately, in one of two ways:

- **GitHub:** open the repository's **Security** tab and choose **Report a vulnerability**.
- **Email:** hello@formhaven.net, with "Security" in the subject.

Please do not open a public issue or post the details anywhere before a fix is out.

## What to include

- What an attacker can do, and what they need first (an account, an API key, a form address, nothing).
- The steps to reproduce it: the request, the page or the code path.
- The commit or date of the code you tested, and whether you tested a self-hosted install or formhaven.net.
- How you want to be credited, if at all.

## What is in scope

- This code, on the `main` branch. That is the only supported version.
- The hosted service at formhaven.net. Test it only with your own account and your own forms. Testing must not harm other customers: no flooding or load tests, no attempts to read or change another account's data, and no spam through other people's forms. If you reach someone else's data by accident, stop, keep nothing, and tell us.

## What you get

Reports are read by one person, the maker. You get a reply, a fix in `main` once there is one, and credit in the fix's notes if you want it. No bug bounty is offered.
