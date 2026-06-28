<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Editable email templates: a subject + Markdown/HTML body with declared
 * variables, managed from the <KinetixMailTemplates> UI and rendered/sent via
 * KinetixMail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kinetix_mail_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('subject');
            $table->longText('body');
            $table->string('format')->default('markdown'); // markdown | html
            $table->json('variables')->nullable();         // [{key,label,sample}]
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kinetix_mail_templates');
    }
};
