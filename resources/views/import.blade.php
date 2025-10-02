<!DOCTYPE html>
<html>
<head>
    <title>CSV Import Users</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-5">

	@if(session('success'))
		<div class="alert alert-success mt-3">
			{{ session('success') }}
		</div>
	@endif


    <h2>Import Users from CSV</h2>
    <form action="{{ route('users.import') }}" method="POST" enctype="multipart/form-data">
        @csrf
        <div class="mb-3">
            <label>Select CSV File</label>
            <input type="file" name="file" class="form-control" accept=".csv" required>
        </div>
        <button type="submit" class="btn btn-primary">Import</button>
    </form>
</div>

</body>
</html>
