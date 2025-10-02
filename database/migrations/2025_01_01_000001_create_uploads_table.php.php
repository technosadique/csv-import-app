<?php 
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('uploads', function (Blueprint $table) {
            $table->id();
            $table->string('upload_id')->unique(); // client-calculated id
            $table->string('filename');
            $table->bigInteger('size')->unsigned();
            $table->integer('total_chunks')->unsigned();
            $table->integer('uploaded_chunks')->unsigned()->default(0);
            $table->string('checksum')->nullable(); // expected sha256 (hex)
            $table->boolean('completed')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uploads');
    }
};
