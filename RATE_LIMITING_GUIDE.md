# Rate Limiting & Usage Tracking Guide

## Overview
FundFinder v3 now includes API rate limiting to protect against excessive API costs. Free users are limited to 3 searches per day, while Pro users have unlimited searches.

## How It Works

### Free Users (subscription_status = 'free')
- **Daily Limit**: 3 AI funding searches per day
- **Reset**: Resets at midnight (00:00) each day
- **Upgrade**: Can upgrade to Pro for unlimited searches

### Pro Users (subscription_status = 'active')
- **Daily Limit**: Unlimited searches
- **No Restrictions**: Full access to AI funding search

## Database Schema

### New Table: usage_logs
```sql
CREATE TABLE usage_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    search_date TEXT NOT NULL,  -- Format: YYYY-MM-DD
    count INT DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY user_date (user_id, search_date)
);
```

**Purpose**: Tracks daily search count per user

**Fields**:
- `user_id`: Links to users table
- `search_date`: Date in YYYY-MM-DD format
- `count`: Number of searches performed that day
- `UNIQUE KEY`: Ensures one row per user per day

## API Changes

### 1. POST /api/search (UPDATED - Now Requires Auth)

**Before**: Public endpoint, anyone could search

**Now**: 
- ✅ **Requires Authentication** - User must be logged in
- ✅ **Rate Limited** - Checks daily usage before allowing search
- ✅ **Auto-Increment** - Increments count only on successful searches

**Request**:
```http
POST /api/search
Content-Type: application/json
Cookie: PHPSESSID=...

{
  "type": "Tech Startup",
  "location": "Seattle",
  "purpose": "Equipment needs"
}
```

**Success Response (200)**:
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

**Error Responses**:

**401 - Not Logged In**:
```json
{
  "error": "Please login to search"
}
```

**429 - Rate Limit Exceeded**:
```json
{
  "error": "Free limit reached. Upgrade to Pro for unlimited searches.",
  "daily_count": 3,
  "limit": 3
}
```

### 2. GET /api/me (UPDATED - Returns Search Info)

**New Field**: `daily_searches_left`

**Response (Free User)**:
```json
{
  "authenticated": true,
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com",
    "subscription_status": "free",
    "daily_searches_left": 2
  }
}
```

**Response (Pro User)**:
```json
{
  "authenticated": true,
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com",
    "subscription_status": "active",
    "daily_searches_left": "unlimited"
  }
}
```

## Flow Diagram

### Search Request Flow
```
User Clicks "Find Funding"
    ↓
Check if logged in?
    ├─ No → Return 401 "Please login to search"
    └─ Yes → Continue
        ↓
Get user's subscription_status
    ├─ Pro (active) → Allow search, skip count check
    └─ Free → Check daily usage
        ↓
Check usage_logs for today
    ├─ Count >= 3 → Return 429 "Free limit reached"
    └─ Count < 3 → Allow search
        ↓
Execute AI Search with Gemini
    ↓
Search successful?
    ├─ Yes → Increment usage count
    └─ No → Don't increment (API error)
        ↓
Return results to user
```

## Helper Functions

### 1. getDailySearchCount($userId)
Gets today's search count for a user.

```php
function getDailySearchCount($userId) {
    $db = getDB();
    if (!$db) return 0;
    
    $today = date('Y-m-d');
    $stmt = $db->prepare("SELECT count FROM usage_logs WHERE user_id = ? AND search_date = ?");
    $stmt->execute([$userId, $today]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result ? (int)$result['count'] : 0;
}
```

### 2. incrementSearchCount($userId)
Increments today's search count (or creates new row if first search of day).

```php
function incrementSearchCount($userId) {
    $db = getDB();
    if (!$db) return false;
    
    $today = date('Y-m-d');
    
    $stmt = $db->prepare("
        INSERT INTO usage_logs (user_id, search_date, count) 
        VALUES (?, ?, 1)
        ON DUPLICATE KEY UPDATE count = count + 1
    ");
    
    return $stmt->execute([$userId, $today]);
}
```

### 3. checkRateLimit($userId)
Checks if user is allowed to make a search.

```php
function checkRateLimit($userId) {
    // Get subscription status
    $user = getUserById($userId);
    
    // Pro users: unlimited
    if ($user['subscription_status'] === 'active') {
        return ['allowed' => true, 'subscription' => 'active'];
    }
    
    // Free users: check limit
    $dailyCount = getDailySearchCount($userId);
    
    if ($dailyCount >= 3) {
        return [
            'allowed' => false, 
            'error' => 'Free limit reached. Upgrade to Pro for unlimited searches.'
        ];
    }
    
    return ['allowed' => true, 'subscription' => 'free'];
}
```

## Frontend Integration

### 1. Check Remaining Searches
```javascript
async function checkSearchesLeft() {
  const response = await fetch('/api/me');
  const data = await response.json();
  
  if (data.authenticated) {
    const searchesLeft = data.user.daily_searches_left;
    
    if (searchesLeft === 'unlimited') {
      document.getElementById('search-status').textContent = 'Pro: Unlimited Searches';
    } else if (searchesLeft === 0) {
      document.getElementById('search-status').textContent = 'Daily limit reached';
      document.getElementById('upgrade-prompt').style.display = 'block';
    } else {
      document.getElementById('search-status').textContent = `${searchesLeft} searches left today`;
    }
  }
}
```

### 2. Handle Search Request
```javascript
async function performSearch(type, location, purpose) {
  try {
    const response = await fetch('/api/search', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ type, location, purpose })
    });
    
    if (response.status === 401) {
      // Not logged in
      alert('Please login to search for funding');
      window.location.href = '/login.html';
      return;
    }
    
    if (response.status === 429) {
      // Rate limit exceeded
      const data = await response.json();
      alert(data.error);
      showUpgradePrompt();
      return;
    }
    
    const results = await response.json();
    displayResults(results);
    
    // Refresh user data to update searches left
    checkSearchesLeft();
    
  } catch (error) {
    console.error('Search failed:', error);
  }
}
```

### 3. Display Search Limits in UI
```html
<div class="search-status">
  <span id="search-status">Loading...</span>
  <div id="upgrade-prompt" style="display:none;">
    <p>You've reached your daily limit of 3 searches.</p>
    <button onclick="window.location.href='/pricing.html'">
      Upgrade to Pro for Unlimited Searches
    </button>
  </div>
</div>

<script>
// Check on page load
checkSearchesLeft();

// Refresh after each search
document.getElementById('search-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  await performSearch(...);
  checkSearchesLeft(); // Update counter
});
</script>
```

## Testing

### Test Free User Limits

1. **Create free user**:
```bash
curl -X POST http://localhost:8000/api/register \
  -H "Content-Type: application/json" \
  -d '{"name":"Free User","email":"free@test.com","password":"pass123"}' \
  -c cookies.txt
```

2. **Make 3 searches** (should succeed):
```bash
for i in {1..3}; do
  curl -X POST http://localhost:8000/api/search \
    -H "Content-Type: application/json" \
    -d '{"type":"Restaurant","location":"Seattle","purpose":"Equipment"}' \
    -b cookies.txt
  echo "\nSearch $i completed"
done
```

3. **Try 4th search** (should fail with 429):
```bash
curl -X POST http://localhost:8000/api/search \
  -H "Content-Type: application/json" \
  -d '{"type":"Restaurant","location":"Seattle","purpose":"Equipment"}' \
  -b cookies.txt
```

4. **Check remaining searches**:
```bash
curl http://localhost:8000/api/me -b cookies.txt
# Should show "daily_searches_left": 0
```

### Test Pro User (Unlimited)

1. **Upgrade user to Pro** (manually in database):
```sql
UPDATE users SET subscription_status = 'active' WHERE email = 'free@test.com';
```

2. **Make 10 searches** (should all succeed):
```bash
for i in {1..10}; do
  curl -X POST http://localhost:8000/api/search \
    -H "Content-Type: application/json" \
    -d '{"type":"Tech","location":"Seattle","purpose":"Equipment"}' \
    -b cookies.txt
done
```

3. **Check status**:
```bash
curl http://localhost:8000/api/me -b cookies.txt
# Should show "daily_searches_left": "unlimited"
```

### Test Daily Reset

The count resets automatically at midnight because we check by date (YYYY-MM-DD).

**Manual reset for testing**:
```sql
-- Delete today's usage log
DELETE FROM usage_logs WHERE user_id = 1 AND search_date = CURDATE();
```

## Database Queries

### Check user's usage
```sql
SELECT u.name, u.email, u.subscription_status, ul.search_date, ul.count
FROM users u
LEFT JOIN usage_logs ul ON u.id = ul.user_id
WHERE u.id = 1;
```

### See all usage today
```sql
SELECT u.name, u.email, ul.count 
FROM users u
JOIN usage_logs ul ON u.id = ul.user_id
WHERE ul.search_date = CURDATE()
ORDER BY ul.count DESC;
```

### Top users by searches
```sql
SELECT u.name, u.email, SUM(ul.count) as total_searches
FROM users u
JOIN usage_logs ul ON u.id = ul.user_id
GROUP BY u.id
ORDER BY total_searches DESC
LIMIT 10;
```

## Configuration

### Change Daily Limit
In `index.php`, update the `checkRateLimit()` function:

```php
// Current: 3 searches for free users
$freeLimit = 3;

// Change to 5:
$freeLimit = 5;
```

### Disable Rate Limiting (for testing)
Temporarily skip rate limit check in `/api/search`:

```php
// Comment out rate limit check
// $rateLimitCheck = checkRateLimit($_SESSION['user_id']);
// if (!$rateLimitCheck['allowed']) { ... }
```

## Monitoring & Analytics

### Track API Usage
```sql
-- Total searches today
SELECT SUM(count) as total_searches_today
FROM usage_logs
WHERE search_date = CURDATE();

-- Average searches per user
SELECT AVG(count) as avg_searches
FROM usage_logs
WHERE search_date = CURDATE();

-- Users who hit the limit
SELECT u.name, u.email
FROM users u
JOIN usage_logs ul ON u.id = ul.user_id
WHERE ul.search_date = CURDATE() AND ul.count >= 3
AND u.subscription_status = 'free';
```

## Cost Protection

### Estimated Savings

**Without Rate Limiting**:
- 100 users × unlimited searches × $0.10/1M tokens
- Potential for abuse, runaway costs

**With Rate Limiting**:
- Free users: 100 × 3 searches/day = 300 searches/day max
- Pro users: Controlled, paying customers only
- **Result**: Predictable, manageable costs

### Gemini API Costs
- Model: gemini-2.0-flash-lite-preview-02-05
- Input: ~$0.10 per 1M tokens
- Output: ~$0.40 per 1M tokens
- Average search: ~500 tokens total
- **Cost per search**: ~$0.0002

**Daily cost estimates**:
- 100 free users (3 searches each) = 300 searches = $0.06/day
- 10 Pro users (10 searches each) = 100 searches = $0.02/day
- **Total**: ~$0.08/day or $2.40/month

## Security Considerations

✅ **Authentication Required**: Only logged-in users can search
✅ **Per-User Tracking**: Rate limits tied to user accounts
✅ **Daily Reset**: Automatic cleanup via date-based logic
✅ **Database Constraints**: UNIQUE key prevents double counting
✅ **Pro Bypass**: Paying customers not restricted

## Troubleshooting

### "Please login to search"
- User session expired or not logged in
- Check cookies are being sent
- Verify `/api/me` returns authenticated

### Count not incrementing
- Check database connection
- Verify `incrementSearchCount()` is called
- Check usage_logs table exists

### Wrong search count
- Clear old usage logs: `DELETE FROM usage_logs WHERE search_date < CURDATE()`
- Check timezone settings (may affect date calculation)

### Pro user still limited
- Verify `subscription_status = 'active'` in database
- Check `checkRateLimit()` logic

---

**Last Updated**: November 24, 2025
**Version**: 3.3 (with Rate Limiting)
