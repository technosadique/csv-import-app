<?php

namespace Database\Factories;

use App\Models\Upload;
use Illuminate\Database\Eloquent\Factories\Factory;

class UploadFactory extends Factory
{
    protected $model = Upload::class;

    public function definition()
    {
        return [
            'upload_id' => $this->faker->uuid,
            'filename'  => $this->faker->lexify('file_??????.jpg'),
            'size'      => $this->faker->numberBetween(1000, 5000),
            'checksum'  => $this->faker->sha1,
        ];
    }
}
