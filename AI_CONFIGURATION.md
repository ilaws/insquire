# InsQuire DeepSeek Configuration

InsQuire sends student feedback, lesson feedback, and teacher evaluation requests to DeepSeek's chat-completions API. The default model is `deepseek-flash` for both student and teacher requests. Thinking mode is disabled for these structured feedback requests to keep responses fast and economical.

Configure these values in the PHP-FPM environment or the server-private `private/config.php` file (a sibling of `public_html`, never inside the web root). If `private/config.php` already returns an array of settings, add the DeepSeek entries to that existing array:

```php
<?php
return [
    'ESSAY_DEEPSEEK_API_KEY' => 'set-this-in-the-server-secret-store',
    'ESSAY_DEEPSEEK_FEEDBACK_MODEL' => 'deepseek-flash',
    'ESSAY_DEEPSEEK_TEACHER_MODEL' => 'deepseek-flash',
    'ESSAY_AI_RATE_LIMIT_PER_MINUTE' => '12',
    'ESSAY_AI_RATE_LIMIT_PER_HOUR' => '100',
    'ESSAY_AI_RATE_LIMIT_PER_DAY' => '10',
];
```

The snippet shows only the new settings; merge them into your existing settings array. Never commit the key or place it in JavaScript, HTML, mobile-app code, or any file under `public_html`. The server sends the key only in the `Authorization` header to DeepSeek. Do not put real credentials in documentation or examples.

Request limits apply per signed-in account, not per IP address. This supports normal classroom traffic from many students sharing a school network while limiting rapid repeated requests from any one account. The default Free-tier limit is 10 requests per rolling 24 hours, with additional burst limits of 12 per minute and 100 per hour. A paid-tier allowance is enforced separately when billing entitlements are configured. Rate-limited requests receive HTTP 429 and a `Retry-After` header; API responses retain their JSON body instead of being replaced by an HTML error page.

Apply the `ai_request_log` table definition in `database/migrations.sql` before deploying the updated PHP files. The log is pruned to the last 24 hours during requests.

Do not include student names, email addresses, or other unnecessary identifying information in AI prompts. Follow your school's policies for student data and third-party processing.

Personal assignment review uses an editable saved draft and a structured review response containing exact draft excerpts, optional replacements, and brief explanations. The draft is saved to the assignment when edited; it is sent to DeepSeek only when the learner selects Review draft. An unchanged draft reuses its saved review and does not make another AI request. Applying a suggestion is always a learner action; InsQuire does not silently edit or submit the assignment.
