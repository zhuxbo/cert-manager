<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (collect(Schema::getIndexes('certs'))->contains('name', 'certs_alternative_names_fulltext')) {
            Schema::table('certs', function (Blueprint $table) {
                $table->dropFullText('certs_alternative_names_fulltext');
            });
        }
    }
};
