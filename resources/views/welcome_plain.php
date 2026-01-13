<!DOCTYPE html>
<html>
<head>
    <title>Plain PHP View</title>
</head>
<body>
    <h1>Hello <?= htmlspecialchars($name ?? 'Guest') ?>!</h1>
    <p>This is a plain PHP file, not Blade.</p>
</body>
</html>
