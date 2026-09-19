EASY FAM PHP SETUP

1. Use PHP-enabled HTTPS hosting (GitHub Pages cannot run webhook.php).
2. Upload index_FAM_PHP_FIXED.html and webhook_easy.php to the same folder.
3. Rename index_FAM_PHP_FIXED.html to index.html.
4. Rename webhook_easy.php to webhook.php.
5. Open webhook.php and replace PASTE_YOUR_FAM_API_KEY_HERE with your FAM API key.
6. In FAM Webhook settings use:
   https://YOUR-DOMAIN/webhook.php?action=webhook
7. Make sure the host allows PHP cURL and lets PHP create the fam_data folder/files.

The payment flow is:
website -> webhook.php -> FAM -> webhook.php -> website/Firebase.

