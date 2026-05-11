INSERT INTO api_keys (id, user_id, verifier, comment)
VALUES ('testclockdev1', 1, SHA2('testclockdev1_secret256', 512), 'Clock wheel dev test')
ON DUPLICATE KEY UPDATE verifier = SHA2('testclockdev1_secret256', 512);
