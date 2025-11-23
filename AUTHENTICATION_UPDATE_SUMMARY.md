# FundFinder v3 - User Authentication Update Summary

## What Was Added

### 1. Database Integration
✅ **PDO MySQL Connection**: Added `getDB()` function for secure database connections
✅ **Auto Table Creation**: `initDatabase()` automatically creates required tables on first run
✅ **Two Tables Created**:
   - `users`: Stores user accounts (id, name, email, password, created_at)
   - `saved_items`: Stores saved funding opportunities linked to users via user_id

### 2. New Authentication Routes

| Endpoint | Method | Auth Required | Description |
|----------|--------|---------------|-------------|
| `/api/register` | POST | No | Create new user account |
| `/api/login` | POST | No | Login with email/password |
| `/api/logout` | POST | No | Destroy user session |
| `/api/me` | GET | No | Check authentication status |

### 3. Protected Routes (NEW)

| Endpoint | Method | Auth Required | Description |
|----------|--------|---------------|-------------|
| `/api/save` | POST | **Yes** | Save a funding item to user's list |
| `/api/saved` | GET | **Yes** | Retrieve user's saved funding items |

### 4. Security Features
✅ **Password Hashing**: BCrypt algorithm via `password_hash()`
✅ **Session Management**: PHP sessions track logged-in users
✅ **SQL Injection Protection**: Prepared statements with PDO
✅ **Auth Middleware**: `requireAuth()` function protects routes
✅ **Unique Email Constraint**: Database prevents duplicate accounts

### 5. Helper Functions
- `getDB()`: Establishes database connection
- `initDatabase()`: Creates tables automatically
- `isAuthenticated()`: Checks if user is logged in
- `requireAuth()`: Middleware to protect routes (returns 401 if not logged in)

## File Structure

```
/workspaces/fundfinder_v3/
├── index.php                    # ✅ UPDATED: Now includes authentication
├── setup_database.sql           # ✅ NEW: Manual database setup script
├── AUTHENTICATION_GUIDE.md      # ✅ NEW: Complete API documentation
├── PROJECT_DOCUMENTATION.md     # Existing project docs
├── index.html                   # Frontend (needs update for auth UI)
├── composer.json                # PHP dependencies
├── .env                         # Environment config (DB credentials)
└── .gitignore                   # Git exclusions
```

## How Authentication Works

### Registration Flow
1. User sends POST to `/api/register` with name, email, password
2. System checks if email already exists (409 error if duplicate)
3. Password is hashed using BCrypt
4. User record created in database
5. Session started with user_id, user_name, user_email
6. Returns user object

### Login Flow
1. User sends POST to `/api/login` with email, password
2. System looks up user by email
3. Verifies password using `password_verify()`
4. If valid, starts session with user data
5. Returns user object
6. If invalid, returns 401 error

### Protected Route Access
1. User makes request to `/api/save` or `/api/saved`
2. `requireAuth()` checks if `$_SESSION['user_id']` exists
3. If yes, allows access
4. If no, returns 401 Unauthorized error

### Session Management
- Sessions automatically created via `session_start()` at top of index.php
- User data stored in `$_SESSION` superglobal
- Logout destroys the session completely
- Sessions persist across requests (cookies handle this)

## Database Schema

### users Table
```sql
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### saved_items Table
```sql
CREATE TABLE saved_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    type VARCHAR(50) NOT NULL,
    amount VARCHAR(100),
    deadline VARCHAR(100),
    link TEXT,
    match_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

## What You Need to Do Next

### 1. Ensure MySQL is Running
```bash
# Check if MySQL is running
systemctl status mysql

# Or on Mac with Homebrew
brew services list
```

### 2. Verify Database Credentials
Check your `.env` file has correct MySQL credentials:
```env
DB_HOST=localhost
DB_NAME=fundfinder
DB_USER=root
DB_PASS=your_password
```

### 3. Test the API
See `AUTHENTICATION_GUIDE.md` for complete testing instructions using curl.

Quick test:
```bash
# Register a user
curl -X POST http://localhost:8000/api/register \
  -H "Content-Type: application/json" \
  -d '{"name":"Test User","email":"test@test.com","password":"pass123"}' \
  -c cookies.txt

# Check if logged in
curl http://localhost:8000/api/me -b cookies.txt
```

### 4. Update Frontend (index.html)
You'll need to add:
- Login/Register forms
- "Save" buttons on search results
- "My Saved Items" page
- Login/Logout buttons in header
- Authentication state management

## API Response Examples

### Successful Registration
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

### Unauthorized Access
```json
{
  "error": "Unauthorized. Please log in."
}
```

### Saved Items List
```json
{
  "items": [
    {
      "id": 1,
      "name": "Seattle Small Business Grant",
      "type": "Grant",
      "amount": "$5,000 - $25,000",
      "deadline": "Dec 31, 2025",
      "link": "https://seattle.gov/grants",
      "match_reason": "Specifically for Seattle businesses",
      "created_at": "2025-11-23 10:30:00"
    }
  ]
}
```

## Error Handling

All errors return appropriate HTTP status codes:
- `400`: Bad Request (missing fields)
- `401`: Unauthorized (not logged in)
- `409`: Conflict (email already exists)
- `500`: Internal Server Error (database issues)

## Security Considerations

### ✅ Implemented
- Password hashing with BCrypt
- Prepared statements (SQL injection protection)
- Session-based authentication
- Database foreign key constraints
- Unique email constraint

### ⏳ Future Enhancements
- CSRF token protection
- Rate limiting on login attempts
- Email verification
- Password reset functionality
- Two-factor authentication (2FA)
- Account lockout after failed attempts
- Password strength requirements
- Remember me functionality

## Testing Checklist

- [ ] MySQL server is running
- [ ] Database credentials in `.env` are correct
- [ ] Can register a new user
- [ ] Can login with correct credentials
- [ ] Cannot login with wrong password
- [ ] Cannot register duplicate email
- [ ] `/api/me` shows authenticated status
- [ ] Can save a funding item when logged in
- [ ] Cannot save item when not logged in
- [ ] Can retrieve saved items
- [ ] Logout destroys session

## Questions & Troubleshooting

### "Database connection failed"
- Verify MySQL is running
- Check `.env` credentials
- Ensure database exists (or let auto-creation handle it)

### "Cannot save items"
- Make sure you're logged in first
- Check cookies are being sent with request
- Verify session is active via `/api/me`

### "Email already registered"
- This is normal behavior preventing duplicates
- Use different email or delete test user from database

---

**Status**: ✅ Authentication fully implemented
**Next Step**: Update frontend UI to use authentication APIs
**Documentation**: See AUTHENTICATION_GUIDE.md for complete API reference
