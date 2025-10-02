<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Illuminate\Http\UploadedFile;

class CsvImportTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
   public function it_imports_and_upserts_users_from_csv()
{
    // Disable CSRF middleware for this test
    $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class);

    // Arrange: create an existing user that will be updated
    $existing = User::create([
        'name' => 'Old Name',
        'email' => 'test@example.com',
    ]);

    // Create a fake CSV file content
    $csvContent = "name,email\n"
        . "John Doe,john@example.com\n"  // new user
        . "Jane Doe,jane@example.com\n"  // new user
        . "Updated Name,test@example.com\n"; // existing user updated

    // Create a fake UploadedFile from CSV content
    $file = UploadedFile::fake()->createWithContent('users.csv', $csvContent);

    // Act: post to the import route with the file
    $response = $this->post('/import-users', [
        'file' => $file,
    ]);

    // Assert: response has JSON success true
    //$response->assertJson(['success' => true]);
dd($response->getContent()); 
    // Assert: database has the new and updated users with correct data
    $this->assertDatabaseHas('users', ['email' => 'john@example.com', 'name' => 'John Doe']);
    $this->assertDatabaseHas('users', ['email' => 'jane@example.com', 'name' => 'Jane Doe']);
    $this->assertDatabaseHas('users', ['email' => 'test@example.com', 'name' => 'Updated Name']);
}

}

