# IntraVox Authorization Model

IntraVox uses Nextcloud's native GroupFolder permissions for authorization. This means that access control is managed entirely through Nextcloud's existing permission system - no separate permission configuration is needed in IntraVox.

## Overview

```
+------------------------------------------------------------------+
|                       Nextcloud Server                            |
|  +--------------------------------------------------------------+ |
|  |                     GroupFolders App                         | |
|  |  +----------------------------------------------------------+ |
|  |  |                 IntraVox GroupFolder                     | |
|  |  |  +--------------------+  +--------------------+          | |
|  |  |  | Group Permissions  |  |    ACL Rules       |          | |
|  |  |  | (Base Access)      |  |  (Fine-grained)    |          | |
|  |  |  +--------------------+  +--------------------+          | |
|  |  +----------------------------------------------------------+ |
|  +--------------------------------------------------------------+ |
|                              |                                    |
|                              v                                    |
|  +--------------------------------------------------------------+ |
|  |                      IntraVox App                            | |
|  |        PermissionService reads permissions                   | |
|  |        and enforces them on all operations                   | |
|  +--------------------------------------------------------------+ |
+------------------------------------------------------------------+
```

## Permission Types

IntraVox respects the standard Nextcloud permission bits:

| Permission | Bit | Description |
|------------|-----|-------------|
| Read | 1 | View pages and content |
| Update | 2 | Edit existing pages |
| Create | 4 | Create new pages |
| Delete | 8 | Delete pages |
| Share | 16 | Required for RSS feed access (public endpoints require Read + Share) |

## How Permissions Work

### 1. Base Permissions (GroupFolder Groups)

When a group is added to the IntraVox GroupFolder, all members of that group receive the configured base permissions. This is the first layer of access control.

Example:
- Group "Employees" has Read permission on IntraVox folder
- Group "Editors" has Read + Write + Create permission
- Group "Admins" has All permissions

### 2. ACL Rules (Fine-grained Control)

If the GroupFolders "Advanced Permissions" (ACL) feature is enabled, administrators can set more specific permissions on subfolders.

> **⚠️ The single most important rule: ACL rules can only _restrict_ access, never _grant_ it.**
>
> An ACL rule can take away a permission a user would otherwise have, but it **cannot add a permission the user's group does not already have at the base level**. The base group permission is the *ceiling*; ACL rules can only lower it for specific paths, never raise it.
>
> This is standard Nextcloud GroupFolders behaviour — not something IntraVox controls. IntraVox simply reads the effective Nextcloud permission on each file and folder.

**What this means in practice:**

- If a user's only group has **Read** as its base permission, granting that user "Read + Write" on a single subfolder via an ACL rule **will not work** — the write is masked away by the read-only base ceiling. The user stays read-only everywhere.
- To let someone edit *some* sections but not others, their group must have **Write at the base level**, and you then use ACL rules to **remove** write on the sections they should not edit.

Example (restrictive model — the correct way):
- Base: "Department Editors" group has **Read + Write + Create** on the whole IntraVox folder
- ACL on `/en/departments/sales` → remove write for everyone except the Sales group
- ACL on `/en/departments/hr` → remove write for everyone except the HR group
- Result: each editor can write only in their own department, read the rest

### 3. Permission Inheritance

Permissions are inherited from parent to child folders, always **narrowing downward**:

- The **base group permission is the maximum** any user can have anywhere in the folder. ACL rules can only reduce it per path.
- A child folder can never have *more* permission than its parent grants.
- ACL rules on a parent folder affect all children beneath it.
- More specific rules (deeper paths) take precedence over less specific ones — but always within the base ceiling.

Because inheritance with Team folders (GroupFolders) works top-down and subtractively, the mental model to keep is: **start broad, then take away** — not "start locked, then grant".

## Setting Up Permissions

### Step 1: Create GroupFolder

1. Go to Nextcloud Admin → GroupFolders
2. Create a folder named "IntraVox"
3. Add groups that should have access

### Step 2: Configure Base Permissions

For each group, set the appropriate permission level:

| Group | Recommended Permissions | Created automatically? |
|-------|------------------------|----------------------|
| IntraVox Users | Read, Share | Yes |
| IntraVox Editors | Read, Write, Create | Yes |
| IntraVox Admins | All | Yes |
| Custom groups (e.g. Department Managers) | Read, Write, Create, Delete, Share | No — add manually |

> **Why Share?** The RSS feed is a public endpoint (no user session). GroupFolders requires both Read and Share permissions for folders to be visible in public requests. Without Share, user feeds will be empty.

### Step 3: Enable ACL (Optional)

For fine-grained control:

1. Enable "Advanced Permissions" on the GroupFolder
2. Navigate to subfolders in Nextcloud Files
3. Click the share icon and configure ACL rules

### Example: Department-based Access

```
IntraVox/
├── en/
│   ├── departments/
│   │   ├── hr/          → HR group: full access, Others: read
│   │   ├── sales/       → Sales group: full access, Others: read
│   │   ├── marketing/   → Marketing group: full access, Others: read
│   │   └── it/          → IT group: full access, Others: read
│   └── news/            → Editors group: full access, Others: read
└── nl/
    └── (same structure)
```

### Example: "Read everything, edit only my section"

A very common request: a user should **read all sections A, B and C**, but only **edit
section B** (their department). The natural-but-wrong instinct is to give the user Read at
the base and add a "Write" ACL on section B — **that does not work**, because an ACL cannot
grant write above a read-only base (see the warning under [ACL Rules](#2-acl-rules-fine-grained-control)).

The correct, restrictive setup:

1. Put the user in a group that has **Read + Write** at the base level of the IntraVox
   folder (e.g. "IntraVox Editors", or a custom "Section B Editors" group).
2. Enable Advanced Permissions on the folder.
3. Add ACL rules that **remove write** on the sections they should *not* edit:
   - Section A → remove write for the group (leave read)
   - Section C → remove write for the group (leave read)
   - Section B → leave as-is (base write applies)

```
IntraVox/
└── en/
    ├── section-a/   → Section B Editors: base write REMOVED via ACL → read-only
    ├── section-b/   → Section B Editors: base write applies → editable
    └── section-c/   → Section B Editors: base write REMOVED via ACL → read-only
```

Result: the user reads A, B and C, but can only edit pages in B.

> **Do not** try to solve this by giving the group Read-only at the base and adding a
> Write ACL on section B. The write will be masked away and the user will be read-only
> everywhere — this is the single most common Team-folder permissions mistake.

## Permission Checks in IntraVox

IntraVox checks permissions at multiple levels:

### API Level
Every API call validates permissions before executing:
- `GET /api/page` - Requires Read permission
- `PUT /api/page` - Requires Write permission
- `POST /api/page` - Requires Create permission
- `DELETE /api/page` - Requires Delete permission

### UI Level
The frontend adapts based on permissions:
- Edit buttons only shown if user has Write permission
- Create page options only shown if user has Create permission
- Delete options only shown if user has Delete permission

### Navigation
Navigation items are filtered based on page permissions - users only see pages they can access.

## Troubleshooting

### User cannot see a page
1. Check if user is member of a group with access to the IntraVox GroupFolder
2. Check ACL rules on the specific folder path
3. Verify the page file exists in the expected location

### User cannot edit a page (even though an ACL grants them write)

This is the most common permissions confusion with Team folders. Symptom: you gave a
user "Read + Write" on one section via an ACL rule, but they still cannot edit pages
there — the Edit button is missing, or saving fails.

**Cause:** the user's *base group* permission is Read-only, and **an ACL rule cannot grant
write above a read-only base** (see [ACL Rules](#2-acl-rules-fine-grained-control) above).
The ACL is capped by the group ceiling, so Nextcloud reports the file as read-only and
IntraVox correctly hides the Edit button.

**Fix — choose one:**

1. **Put the editors in a group that has Write at the base level** (e.g. the built-in
   "IntraVox Editors", which has Read + Write + Create), then use ACL rules to *remove*
   write on the sections they should not edit. This is the recommended model.
2. Or raise the base permission of the user's existing group to include Write, and use
   ACL rules to restrict it back down on the read-only sections.

**How to verify what the user actually has:** run, on the server,
`occ groupfolders:permissions <folderId> <path> --test -u <user>` to see the effective
permission for that path. If it shows `+write` but editing still fails, re-check that the
user's *group* has write at the base (the `--test` output reflects the ACL rule, but the
effective node permission is still capped by the group base).

### User cannot edit a page (other causes)
1. Verify the user's group has Write permission on the GroupFolder **at the base level**
2. Check if ACL rules explicitly *remove* Write access on that path
3. Check parent folder permissions (a child can never exceed its parent, and never exceed the base group ceiling)

### User's RSS feed is empty
1. Check that the user's group has **Share** permission on the GroupFolder (base level)
2. If using ACL: verify Share permission on the language folder (`en/`, `nl/`, etc.) and all parent folders
3. Verify that "Allow users to share via link and emails" is enabled in Nextcloud Admin → Sharing
4. See [RSS_FEED.md](../user/rss-feeds.md#administrator-setup) for the full setup guide

### Navigation shows pages user cannot access
This should not happen if permissions are configured correctly. Check:
1. Navigation file permissions vs page file permissions
2. Cache issues - try clearing Nextcloud cache

## Technical Implementation

IntraVox does **not** compute permissions itself. It reads the **effective Nextcloud
permission** on each page file and folder through the user's own mounted view, which
already has all GroupFolder base permissions and ACL rules applied by Nextcloud:

```php
// Per-page write is gated on the page's own JSON file, via the user's ACL-aware view.
// canWrite = the UPDATE bit is set AND the node reports isUpdateable() for this user.
$canWrite = ($file->getPermissions() & 2) !== 0 && $file->isUpdateable();
```

This is why an ACL rule that *appears* to grant write (in the ACL editor or in
`occ groupfolders:permissions … --test`) can still leave a page read-only: the ACL rule is
recorded, but the **effective node permission** Nextcloud hands to IntraVox is capped by
the group's base permission. IntraVox faithfully reflects whatever Nextcloud reports — so
the fix always lives in the GroupFolder base permissions + ACL configuration, never in
IntraVox itself.

Because permissions are per-user and read live from the filesystem view, they are never
cached across users: IntraVox recomputes `canWrite` on every read so one user's access can
never leak to another.

## Best Practices

1. **Use Groups** - Always assign permissions to groups, not individual users
2. **Principle of Least Privilege** - Start with read-only and add permissions as needed
3. **Document Your Structure** - Keep a record of which groups have access to what
4. **Test Thoroughly** - After setting up permissions, test with users from each group
5. **Regular Audits** - Periodically review group memberships and ACL rules
6. **RSS Feed requires Share** - The RSS feed is a public endpoint. GroupFolders requires both Read and Share permissions for folders to be visible in public (unauthenticated) requests. If users report empty feeds, check that their group has Share permission on the relevant folders.
