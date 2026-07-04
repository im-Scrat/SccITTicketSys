<?php

declare(strict_types=1);

/*
 * Bundled list of the most common passwords, rejected by PasswordPolicy
 * (SRS FR-AUTH-003). This is the single place to maintain the deny-list; to
 * approach the full "top 1,000", append entries from a source such as
 * SecLists (rockyou-top). Kept offline by design (no HaveIBeenPwned/CDN call)
 * for locked-down networks (NFR-CMP-005). Compared case-insensitively.
 */

return [
    '123456', '123456789', '12345678', '1234567', '1234567890', '12345', '1234',
    '111111', '000000', '123123', '654321', '666666', '888888', '121212', '112233',
    '789456', '159753', '987654321', '11111111', '00000000', '1234554321',
    'password', 'password1', 'password123', 'passw0rd', 'p@ssw0rd', 'p@ssword',
    'pass', 'passwd', 'admin', 'admin123', 'administrator', 'root', 'toor',
    'qwerty', 'qwerty123', 'qwertyuiop', 'qwerty1', 'asdfgh', 'asdfghjkl',
    'zxcvbnm', 'qazwsx', 'qazwsxedc', '1q2w3e4r', '1q2w3e4r5t', '1qaz2wsx',
    'zaq12wsx', 'q1w2e3r4', 'a1b2c3d4', 'abc123', 'abcd1234', 'abcdef', 'abcdefg',
    'letmein', 'welcome', 'welcome1', 'welcome123', 'iloveyou', 'monkey',
    'dragon', 'sunshine', 'princess', 'football', 'baseball', 'basketball',
    'superman', 'batman', 'trustno1', 'master', 'hello', 'hello123', 'freedom',
    'whatever', 'shadow', 'michael', 'jennifer', 'jordan', 'harley', 'ranger',
    'hunter', 'buster', 'thomas', 'robert', 'soccer', 'startrek', 'starwars',
    'computer', 'internet', 'samsung', 'google', 'facebook', 'login', 'test',
    'test123', 'testing', 'guest', 'guest123', 'default', 'changeme', 'secret',
    'access', 'money', 'love', 'sex', 'god', 'life', 'ninja', 'azerty',
    'summer', 'winter', 'spring', 'autumn', 'january', 'february', 'december',
    'flower', 'cookie', 'chocolate', 'pepper', 'ginger', 'maggie', 'jessica',
    'ashley', 'amanda', 'nicole', 'daniel', 'andrew', 'joshua', 'matthew',
    'charlie', 'donald', 'michelle', 'tigger', 'purple', 'orange', 'yellow',
    'silver', 'gold', 'diamond', 'lovely', 'angel', 'baby', 'cheese',
    'passwordpassword', 'letmein123', 'admin1234', 'root123', 'toor123',
    'qwe123', 'asd123', 'zxc123', '123qwe', '123abc', 'a123456', '123456a',
    'password12', 'password1234', 'passw0rd1', 'p@ssw0rd1', 'welcome@123',
    'admin@123', 'admin@1234', 'root@123', 'user', 'user123', 'user1234',
    'sccit', 'sccit123', 'school', 'school123', 'teacher', 'technician',
    'support', 'helpdesk', 'service', 'manager', 'office', 'company',
    'iloveyou1', 'iloveyou123', 'monkey123', 'dragon123', 'sunshine1',
    'football1', 'superman1', 'batman123', 'shadow1', 'master123',
    'trustno1!', 'p@55w0rd', 'p@55word', 'Password1', 'Password123', 'Password@1',
    'Welcome1', 'Welcome123', 'Admin@123', 'Qwerty123', 'Abcd1234',
];
