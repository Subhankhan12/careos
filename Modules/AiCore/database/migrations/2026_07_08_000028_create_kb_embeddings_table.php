<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_embeddings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('kb_article_id');
            $table->string('embedding_model');
            $table->json('vector');
            $table->char('content_hash', 64);
            $table->timestamps();

            $table->unique(['tenant_id', 'kb_article_id', 'embedding_model'], 'kb_embeddings_unique_article_model');
            $table->index(['tenant_id', 'embedding_model']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_embeddings');
    }
};
