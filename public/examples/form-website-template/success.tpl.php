@section success "Success page"
@param successTitle String = "Thank you" label="Success heading"
@endsection
@layout
<?php
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Please submit the form.');
}
$name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
$email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
if ($name === '' || strlen($name) > 120 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    exit('Please provide a valid name and email.');
}
// Add your CRM/API delivery here. This example only displays the submission.
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{successTitle}}</title><link rel="stylesheet" href="/assets/site.css"></head>
<body><main>
<h1>{{successTitle}}</h1>
<p>Hello, <?= htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>.</p>
<p>Your email: <?= htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
<a href="/">Back to the form</a>
</main></body></html>
@endlayout
