<?php 
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;

class UserImportController extends Controller
{
    public function showForm()
    {
        return view('import');
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:csv,txt|max:2048'
        ]);

        $file = $request->file('file');

        $handle = fopen($file, "r");
        $header = fgetcsv($handle); // Skip first row (headers)

        $total = 0; $updated = 0; $invalid = 0; $imported = 0; $duplicates = 0;

        $total = 0; 
		$imported = 0;   // new records
		$updated = 0;    // updated existing DB records
		$invalid = 0; 
		$duplicates = 0;

		$seenEmails = []; // track duplicates inside CSV

		while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
			$total++;

			if (count($row) < 2 || empty($row[0]) || empty($row[1])) {
				$invalid++;
				continue;
			}

			[$name, $email] = $row;
			$email = trim(strtolower($email));

			// check CSV duplicates
			if (in_array($email, $seenEmails)) {
				$duplicates++;
				continue; // skip this row
			}
			$seenEmails[] = $email;

			// check DB first
			$existing = User::where('email', $email)->first();

			if ($existing) {
				$existing->name = $name;
				$existing->save();
				$updated++;
			} else {
				User::create(['name' => $name, 'email' => $email]);
				$imported++;
			}
		}
        fclose($handle);

        return back()->with('success', "Import Summary:
            Total: $total | Imported: $imported | Updated: $updated | Invalid: $invalid | Duplicates: $duplicates");
    }
}
