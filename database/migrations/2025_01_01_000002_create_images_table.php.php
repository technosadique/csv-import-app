<?php 
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('upload_id')->constrained('uploads')->onDelete('cascade');
            $table->string('variant'); // original, 256, 512, 1024
            $table->string('path');
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->string('entity_type')->nullable(); // optional linking e.g. App\Models\User
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('images');
    }
};
