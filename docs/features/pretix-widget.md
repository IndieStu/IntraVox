# Pretix widget

The Pretix widget shows the next active occurrence of a configured event or
event series. It can display the date and time, location, capacity, estimated
registrations, remaining availability and the number of orders created within a
configurable time window.

## Configure the connection

1. In Pretix, create a dedicated team API token. Grant it access only to the
   organizer and events that IntraVox should display. Reading orders is needed
   for the “new registrations” counter; no write permission is required.
2. Open **Administration settings → IntraVox → Pretix**.
3. Enter the public HTTPS origin of the Pretix instance and the API token.
4. Save. The token is encrypted with the Nextcloud instance crypto service and
   is never returned to the browser. Leaving the token field empty later keeps
   the stored token.

The configured host must resolve exclusively to public IP addresses. Redirects,
embedded credentials, query strings and fragments are rejected. All requests
use a fixed Pretix API path below this admin-controlled origin.

## Add the widget

Edit an IntraVox page, choose **Add widget → Pretix event**, then select an
organizer and event. For event series the nearest active, public occurrence is
selected automatically. An optional quota ID can pin the capacity calculation
to one specific quota; otherwise the first quota participating in event
availability is used.

The default “new registrations” window is 24 hours and can be set between 1 and
168 hours. The Pretix backend link is disabled by default.

## Data handling and failure mode

Pretix is called only by the Nextcloud server. The API token, order records and
personal attendee data are never included in the page JSON or widget response.
Each widget request is also checked against the current user's read permission
for the containing IntraVox page.
The normalized summary is cached for three minutes. If Pretix is unavailable or
rejects authentication, visitors see a short neutral warning while the rest of
the page remains usable.

Capacity minus currently available places is shown as the registration estimate.
Unlimited quotas are shown as unlimited instead of a numeric capacity.
