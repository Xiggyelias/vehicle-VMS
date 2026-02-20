# API Reference (AJAX Endpoints)

This document covers the primary AJAX endpoints used by the frontend.

## Common Rules

- Base URL: same origin as the app (for example `https://your-domain.tld`).
- Auth: endpoints require an authenticated session (`logged_in = true`).
- CSRF: for `POST` requests, send either:
  - form field: `_token`, or
  - header: `X-CSRF-Token`.
- Content type:
  - most actions use `application/x-www-form-urlencoded` or `multipart/form-data`.
- Response format: JSON.

---

## `vehicle_operations.php`

Vehicle management endpoint with action-based behavior.

### Method

- `POST`

### Request Fields

- `action` (required): one of `add`, `edit`, `delete`, `delete_driver`.

### `action=add`

Adds a new vehicle and deactivates previously active vehicles for the same applicant.

Request fields:
- `action=add`
- `make` (required)
- `regNumber` (required)

Success response example:
```json
{
  "status": "success",
  "success": true,
  "message": "Vehicle added successfully!",
  "vehicle": {
    "vehicle_id": 123,
    "make": "Toyota",
    "regNumber": "ABC123",
    "status": "active",
    "formatted_last_updated": "Feb 18, 2026 4:15 PM"
  },
  "deactivated_vehicle_ids": [77, 88],
  "deactivated_count": 2
}
```

### `action=edit`

Updates an existing vehicle owned by the authenticated applicant.

Request fields:
- `action=edit`
- `id` (required, vehicle id)
- `make` (required)
- `regNumber` (required)

Success response example:
```json
{
  "status": "success",
  "message": "Vehicle updated successfully!"
}
```

### `action=delete`

Deletes a vehicle owned by the authenticated applicant.

Request fields:
- `action=delete`
- `id` (required, vehicle id)

Success response example:
```json
{
  "status": "success",
  "message": "Vehicle deleted"
}
```

### `action=delete_driver`

Deletes a driver record linked to the authenticated applicant.

Request fields:
- `action=delete_driver`
- `id` (required, driver id)

Success response example:
```json
{
  "status": "success",
  "success": true,
  "message": "Driver deleted"
}
```

### Typical error responses

```json
{ "status": "error", "message": "Please log in to continue." }
```

```json
{ "status": "error", "message": "Invalid action specified." }
```

HTTP statuses commonly used:
- `200` success
- `400` bad request/validation failure
- `401` unauthenticated
- `404` not found (select cases)
- `405` wrong method

---

## `driver_operations.php`

Authorized driver management endpoint with action-based behavior.

### Method

- `POST`

### Request Fields

- `action` (required): one of `add`, `edit`, `delete`.

### `action=add`

Creates a new authorized driver.

Request fields:
- `action=add`
- `fullname` (required)
- `licenseNumber` (required)
- `contact` (optional)

Success response example:
```json
{
  "status": "success",
  "success": true,
  "message": "Driver added successfully!",
  "driver": {
    "Id": 19,
    "fullname": "Jane Doe",
    "licenseNumber": "AB123456",
    "contact": "0771234567"
  }
}
```

### `action=edit`

Updates an authorized driver.

Request fields:
- `action=edit`
- `driver_id` (required)
- `fullname` (required)
- `licenseNumber` (required)
- `contact` (optional)

Success response example:
```json
{
  "status": "success",
  "success": true,
  "message": "Driver updated successfully!",
  "driver": {
    "Id": 19,
    "fullname": "Jane Doe",
    "licenseNumber": "AB123456",
    "contact": "0771234567"
  }
}
```

### `action=delete`

Deletes an authorized driver by id.

Request fields:
- `action=delete`
- `driver_id` (required)

Success response example:
```json
{
  "status": "success",
  "success": true,
  "message": "Driver deleted successfully!"
}
```

### Typical error responses

```json
{ "status": "error", "success": false, "message": "Please log in to continue." }
```

```json
{ "status": "error", "success": false, "message": "Invalid request method." }
```

HTTP statuses commonly used:
- `200` success
- `400` bad request/validation failure
- `401` unauthenticated
- `405` wrong method

