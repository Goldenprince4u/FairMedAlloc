INSERT INTO users (
    username,
    full_name,
    email,
    password_hash,
    must_change_password,
    role,
    profile_pic
)
SELECT
    'AbdulQuadri',
    'AbdulQuadri',
    NULL,
    '$2y$10$I3FvWJZ0U7WYXcAW.GNp5ut8PvN6z/o6C1LhPTkR/PKuwvUYYUWsq',
    0,
    'admin',
    'default.png'
WHERE NOT EXISTS (
    SELECT 1
    FROM users
    WHERE username = 'AbdulQuadri'
);
