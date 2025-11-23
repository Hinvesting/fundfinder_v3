# User Authentication Setup Guide

## Overview
FundFinder v3 now includes user authentication with protected routes for saving and retrieving funding opportunities.

## Database Setup

### Option 1: Automatic (Recommended)
The database tables are automatically created when you first access the application. Just ensure your MySQL server is running and the credentials in `.env` are correct.

### Option 2: Manual Setup
Run the SQL script manually:
```bash
mysql -u root -p < setup_database.sql
```

## Environment Configuration

Update your `.env` file with database credentials:
```env
DB_HOST=localhost
DB_NAME=fundfinder
DB_USER=root
DB_PASS=your_password_here
```

## API Endpoints

### Authentication Endpoints

#### 1. Register New User
```http
POST /api/register
Content-Type: application/json

{
  "name": "John Doe",
  "email": "john@example.com",
  "password": "securepassword123"
}
```

**Success Response (200):**
```json
{
  "success": true,
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com"
  }
}
```

**Error Responses:**
- `400`: Missing required fields
- `409`: Email already registered
- `500`: Database error

#### 2. Login
```http
POST /api/login
Content-Type: application/json

{
  "email": "john@example.com",
  "password": "securepassword123"
}
```

**Success Response (200):**
```json
{
  "success": true,
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com"
  }
}
```

**Error Responses:**
- `400`: Missing required fields
- `401`: Invalid email or password
- `500`: Database error

#### 3. Logout
```http
POST /api/logout
```

**Success Response (200):**
```json
{
  "success": true,
  "message": "Logged out successfully"
}
```

#### 4. Check Authentication Status
```http
GET /api/me
```

**Response (200) - Authenticated:**
```json
{
  "authenticated": true,
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com"
  }
}
```

**Response (200) - Not Authenticated:**
```json
{
  "authenticated": false
}
```

### Protected Endpoints (Require Authentication)

#### 5. Save Funding Item
```http
POST /api/save
Content-Type: application/json
Cookie: PHPSESSID=...

{
  "name": "Small Business Grant",
  "type": "Grant",
  "amount": "$5,000 - $20,000",
  "deadline": "Dec 31, 2025",
  "link": "https://example.com/apply",
  "match_reason": "Available for Seattle businesses"
}
```

**Success Response (200):**
```json
{
  "success": true,
  "message": "Item saved successfully"
}
```

**Error Responses:**
- `401`: Not authenticated
- `400`: Missing required fields
- `500`: Database error

#### 6. Get Saved Items
```http
GET /api/saved
Cookie: PHPSESSID=...
```

**Success Response (200):**
```json
{
  "items": [
    {
      "id": 1,
      "name": "Small Business Grant",
      "type": "Grant",
      "amount": "$5,000 - $20,000",
      "deadline": "Dec 31, 2025",
      "link": "https://example.com/apply",
      "match_reason": "Available for Seattle businesses",
      "created_at": "2025-11-23 10:30:00"
    }
  ]
}
```

**Error Responses:**
- `401`: Not authenticated
- `500`: Database error

### Public Endpoints (No Authentication Required)

#### 7. AI Funding Search
```http
POST /api/search
Content-Type: application/json

{
  "type": "Tech Startup",
  "location": "Seattle",
  "purpose": "Equipment needs"
}
```

**Success Response (200):**
```json
[
  {
    "name": "Seattle Tech Fund",
    "type": "Grant",
    "amount": "$10,000 - $50,000",
    "deadline": "Rolling",
    "link": "https://example.com",
    "match_reason": "Specifically for Seattle tech startups"
  }
]
```

## Database Schema

### users table
| Column | Type | Description |
|--------|------|-------------|
| id | INT | Primary key (auto-increment) |
| name | VARCHAR(255) | User's full name |
| email | VARCHAR(255) | Unique email address |
| password | VARCHAR(255) | Bcrypt hashed password |
| created_at | TIMESTAMP | Account creation timestamp |

### saved_items table
| Column | Type | Description |
|--------|------|-------------|
| id | INT | Primary key (auto-increment) |
| user_id | INT | Foreign key to users.id |
| name | VARCHAR(255) | Funding source name |
| type | VARCHAR(50) | Grant/Loan/Investor |
| amount | VARCHAR(100) | Funding amount range |
| deadline | VARCHAR(100) | Application deadline |
| link | TEXT | Application URL |
| match_reason | TEXT | Why this matches the user |
| created_at | TIMESTAMP | Save timestamp |

## Security Features

1. **Password Hashing**: Uses PHP's `password_hash()` with BCRYPT algorithm
2. **Session Management**: Secure PHP sessions for authentication state
3. **Protected Routes**: Middleware checks authentication before allowing access
4. **SQL Injection Prevention**: Prepared statements with PDO
5. **Unique Email Constraint**: Database-level enforcement

## Frontend Integration Example

```javascript
// Register
async function register(name, email, password) {
  const response = await fetch('/api/register', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ name, email, password })
  });
  return await response.json();
}

// Login
async function login(email, password) {
  const response = await fetch('/api/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email, password })
  });
  return await response.json();
}

// Check auth status
async function checkAuth() {
  const response = await fetch('/api/me');
  return await response.json();
}

// Save funding item
async function saveFunding(item) {
  const response = await fetch('/api/save', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(item)
  });
  return await response.json();
}

// Get saved items
async function getSavedItems() {
  const response = await fetch('/api/saved');
  return await response.json();
}

// Logout
async function logout() {
  const response = await fetch('/api/logout', { method: 'POST' });
  return await response.json();
}
```

## Testing the Authentication

### 1. Start the PHP server:
```bash
php -S localhost:8000
```

### 2. Test with curl:

**Register:**
```bash
curl -X POST http://localhost:8000/api/register \
  -H "Content-Type: application/json" \
  -d '{"name":"Test User","email":"test@example.com","password":"password123"}' \
  -c cookies.txt
```

**Login:**
```bash
curl -X POST http://localhost:8000/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"password123"}' \
  -c cookies.txt
```

**Check Auth:**
```bash
curl http://localhost:8000/api/me -b cookies.txt
```

**Save Item:**
```bash
curl -X POST http://localhost:8000/api/save \
  -H "Content-Type: application/json" \
  -d '{"name":"Test Grant","type":"Grant"}' \
  -b cookies.txt
```

**Get Saved Items:**
```bash
curl http://localhost:8000/api/saved -b cookies.txt
```

**Logout:**
```bash
curl -X POST http://localhost:8000/api/logout -b cookies.txt
```

## Troubleshooting

### "Database connection failed"
- Check MySQL is running: `systemctl status mysql` or `brew services list`
- Verify credentials in `.env` file
- Test connection: `mysql -u root -p`

### "Email already registered"
- This is expected if you try to register the same email twice
- Use a different email or delete the user from the database

### "Unauthorized" errors
- Make sure you're sending cookies with requests
- Check that login was successful
- Verify session is active: `curl http://localhost:8000/api/me -b cookies.txt`

### Tables not created
- Run the SQL script manually: `mysql -u root -p < setup_database.sql`
- Check database permissions

## Next Steps

1. Update `index.html` to include login/register forms
2. Add "Save" buttons to funding results
3. Create a "My Saved Items" page
4. Add password reset functionality
5. Implement email verification
6. Add rate limiting for API endpoints

---

**Last Updated**: November 23, 2025
**Version**: 3.1 (with Authentication)
