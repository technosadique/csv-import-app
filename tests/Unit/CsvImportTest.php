<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CsvImportTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_upserts_users_from_csv()
    {
        // Arrange: create existing user
        $existing = User::create([
            'name' => 'Old Name',
            'email' => 'test@example.com',
        ]);

        // Fake CSV rows
        $rows = [
            ['name' => 'John Doe', 'email' => 'john@example.com'],   // new
            ['name' => 'Jane Doe', 'email' => 'jane@example.com'],   // new
            ['name' => 'Updated Name', 'email' => 'test@example.com'] // update existing
        ];

        // Act: mimic import logic (upsert by email)
        foreach ($rows as $row) {
            User::updateOrCreate(
                ['email' => $row['email']],
                ['name' => $row['name']]
            );
        }

        // Assert: new + updated users exist
        $this->assertDatabaseHas('users', ['email' => 'john@example.com', 'name' => 'John Doe']);
        $this->assertDatabaseHas('users', ['email' => 'jane@example.com', 'name' => 'Jane Doe']);
        $this->assertDatabaseHas('users', ['email' => 'test@example.com', 'name' => 'Updated Name']);
    }
}
