-- DB Viewer — one small table for named, saved SQL snippets.
-- Run this once in phpMyAdmin against the shared MyDataWorld database.
-- Everything else the tool uses (users, sessions) already exists.
--
-- Saved queries are shared by all admins (there is normally just one) and are
-- only ever *stored* here — running one still goes through the same read-only
-- guard as anything typed by hand.

CREATE TABLE IF NOT EXISTS db_viewer_queries (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(150) NOT NULL UNIQUE,
  sql_text    TEXT NOT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A few starters (safe to re-run — ON DUPLICATE KEY UPDATE refreshes the text).
INSERT INTO db_viewer_queries (name, sql_text) VALUES
  ('App usage — recent',
'SELECT
    l.access_date                 AS AccessDate,
    COALESCE(a.name, l.app_key)   AS App,
    u.username                    AS UserEmail,
    u.display_name                AS UserName,
    l.hit_count                   AS Hits,
    l.first_seen_at               AS FirstSeen,
    l.last_seen_at                AS LastSeen
FROM app_usage_log l
JOIN users u       ON u.id = l.user_id
LEFT JOIN apps a   ON a.app_key = l.app_key
-- WHERE l.access_date >= CURDATE() - INTERVAL 30 DAY   -- date range
-- AND   l.app_key = ''choir-connect''                   -- one app
ORDER BY l.last_seen_at DESC, App, u.username'),

  ('Tables & approximate row counts',
'SELECT TABLE_NAME AS TableName, TABLE_ROWS AS ApproxRows, UPDATE_TIME AS Updated
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
ORDER BY TABLE_NAME'),

  ('Users',
'SELECT id, username, display_name, is_admin, created_at
FROM users
ORDER BY created_at DESC'),

  ('Apps and who can see them',
'SELECT a.app_key AS AppKey, a.name AS App, a.is_public AS IsPublic,
       COUNT(aa.user_id) AS GrantedUsers
FROM apps a
LEFT JOIN app_access aa ON aa.app_id = a.id
GROUP BY a.id, a.app_key, a.name, a.is_public
ORDER BY a.name')
ON DUPLICATE KEY UPDATE sql_text = VALUES(sql_text);
