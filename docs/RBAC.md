# Authentication & RBAC (M1)

## Authentication

Custom Livewire components on top of Laravel's built-in guard / password broker
(no Breeze/Fortify/Jetstream).

| Screen | Component | Route |
| --- | --- | --- |
| Sign in | `App\Livewire\Auth\Login` | `GET /login` |
| Forgot password | `App\Livewire\Auth\ForgotPassword` | `GET /forgot-password` |
| Reset password | `App\Livewire\Auth\ResetPassword` | `GET /reset-password/{token}` |
| Sign out | `App\Http\Controllers\Auth\LogoutController` | `POST /logout` |

Security properties:

- Passwords hashed with bcrypt (`casts()` → `'password' => 'hashed'`); never logged.
- CSRF on every POST / Livewire request (framework default).
- Session **regenerated** on login, **invalidated + token regenerated** on logout.
- Login **throttled**: 5 attempts per `email|ip` per minute (`RateLimiter`), fires `Lockout`.
- **Inactive users** are refused at login *and* `EnsureUserIsActive` middleware
  (`active` alias) logs out any session whose user is later deactivated.
- Forgot-password never reveals whether an email exists.

## RBAC — `spatie/laravel-permission`

```
User ──*..*── Role ──*..*── Permission
         (model_has_roles)   (role_has_permissions)
```

A user may hold **multiple roles**; effective permissions are the union.
Direct user permissions are supported by the package but not used by the UI.

### SUPER ADMIN

`AppServiceProvider` registers `Gate::before()` → a user with the `Super Admin`
role passes **every** ability check. This is the only place a role name is checked
directly; everything else asks for a permission.

### Enforcement layers (all three, never UI-only)

1. **Route / middleware** — `permission:users.view` etc. in `routes/web.php`.
2. **Component / action** — every Livewire action calls `$this->authorize(...)`;
   Actions re-check domain invariants and throw `App\Exceptions\DomainException`.
3. **Policy** — `UserPolicy`, `RolePolicy` (auto-discovered; `App\Models\Role`
   extends the Spatie model so the policy binds).

### Permission naming

`module.action` — single source of truth in `App\Enums\Permission` (38 cases).
`App\Enums\PermissionGroup` drives the grouped role editor UI. Permissions for
not-yet-built modules (projects, plots, buyers, bookings, payments, registry,
possession, associates, reports) are **seeded** so roles can be prepared, but
nothing references them until those modules ship.

### System roles (`App\Enums\RoleName`, seeded, un-deletable)

Super Admin · Admin · Sales Manager · Sales Executive · Accountant ·
Registry Manager · Possession Manager · Associate Manager · Viewer

Custom roles can be created in the UI. The `Super Admin` role's permission set is
immutable (it's all-powerful via `Gate::before` regardless).

### Administrator lock-out protection (`App\Support\Access\AdministratorGuard`)

Enforced in policies **and** Actions:

- you cannot deactivate or delete **your own** account;
- you cannot remove the `Super Admin` role from, deactivate, or delete the
  **last active Super Admin**;
- system roles cannot be deleted; roles with users assigned cannot be deleted.

## Seeded accounts

`php artisan db:seed` (run automatically by `migrate:fresh --seed`):

| Email | Password | Role |
| --- | --- | --- |
| `super@kingbuilders.test` | `password` | Super Admin |
| `viewer@kingbuilders.test` | `password` | Viewer (local/testing only) |
