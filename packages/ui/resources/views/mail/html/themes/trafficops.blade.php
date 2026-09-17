/* Email-safe equivalents of resources/css/theme.css and brand.css.
   Use literal colors: the Markdown mailer inlines these declarations. */
body, body * {
    box-sizing: border-box;
    font-family: 'Onest Variable', -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
}
body {
    background-color: #fbf7f2;
    color: #241f1d;
    height: 100%;
    line-height: 1.6;
    margin: 0;
    padding: 0;
    width: 100% !important;
    -webkit-text-size-adjust: none;
}
table { border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
td { word-break: break-word; }
p, ul, ol, blockquote { font-size: 16px; line-height: 1.6; margin: 0 0 20px; }
a { color: #241f1d; text-decoration: underline; }
img { border: 0; max-width: 100%; }
h1 { color: #241f1d; font-size: 30px; font-weight: 600; letter-spacing: -1px; line-height: 1.2; margin: 0 0 24px; text-align: left; }
h2 { color: #241f1d; font-size: 22px; font-weight: 600; line-height: 1.3; margin: 0 0 16px; }
h3 { color: #241f1d; font-size: 18px; font-weight: 600; margin: 0 0 12px; }
strong { font-weight: 600; }
.wrapper { background-color: #fbf7f2; margin: 0; padding: 0; width: 100%; }
.content { margin: 0; padding: 0; width: 100%; }
.header { padding: 40px 16px 32px; }
.brand-icon img { display: block; }
.brand-wordmark { color: #241f1d; font-size: 26px; font-weight: 700; letter-spacing: -1.3px; line-height: 1.2; text-decoration: none; white-space: nowrap; }
.brand-accent { color: #f04426; }
.brand-byline { color: #766e69; font-size: 12px; line-height: 1.5; margin: 3px 0 0; }
.brand-byline a { color: #766e69; text-decoration: none; }
.body { padding: 0 16px; }
.inner-body { background-color: #fffdfb; border: 1px solid #e7ddd6; border-top: 3px solid #e75d45; border-collapse: separate; border-radius: 12px; margin: 0 auto; width: 570px; }
.content-cell { padding: 40px; }
.content-cell > :last-child { margin-bottom: 0; }
.action { margin: 28px auto; padding: 0; text-align: center; width: 100%; }
.button { border-radius: 9px; display: inline-block; font-size: 16px; font-weight: 600; line-height: 1.5; text-align: center; text-decoration: none; -webkit-text-size-adjust: none; }
/* Dark text on coral follows the brand guide's contrast recommendation. */
.button-primary { background-color: #e75d45; border-top: 12px solid #e75d45; border-right: 24px solid #e75d45; border-bottom: 12px solid #e75d45; border-left: 24px solid #e75d45; color: #241f1d; }
.button-success, .button-error { background-color: #2f2926; border-top: 12px solid #2f2926; border-right: 24px solid #2f2926; border-bottom: 12px solid #2f2926; border-left: 24px solid #2f2926; color: #fffaf6; }
.subcopy { border-top: 1px solid #e7ddd6; margin-top: 28px; padding-top: 24px; }
.subcopy td { padding-top: 24px; }
.subcopy p { color: #766e69; font-size: 13px; line-height: 1.6; }
.subcopy a, .break-all { word-break: break-all; overflow-wrap: anywhere; }
.footer { margin: 0 auto; text-align: center; width: 570px; }
.footer .content-cell { padding: 28px 24px 40px; }
.footer p, .footer a { color: #766e69; font-size: 12px; line-height: 1.6; text-align: center; }
.panel { border-collapse: separate; border-spacing: 0; margin: 24px 0; }
.panel-content { background-color: #fbf7f2; border: 1px solid #e7ddd6; border-radius: 9px; padding: 20px; }
.panel-item { padding: 0; }
.panel-item p:last-child { margin-bottom: 0; }
code, pre { font-family: Menlo, Consolas, monospace; font-size: 14px; }
code { overflow-wrap: anywhere; }
pre { background-color: #fbf7f2; padding: 16px; white-space: pre-wrap; }
.table table { margin: 24px auto; width: 100%; }
.table th { border-bottom: 1px solid #e7ddd6; padding: 8px; text-align: left; }
.table td { font-size: 14px; line-height: 1.6; padding: 8px; }
hr { border: 0; border-top: 1px solid #e7ddd6; margin: 24px 0; }
