<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Votes held in discussions: the poll itself, its options and the votes of members.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('polls', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('discussion_id')->unsigned()->index();
            $table->integer('user_id')->unsigned()->index();
            $table->string('type');
            $table->string('question');
            $table->text('body')->nullable();
            $table->dateTime('closes_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('poll_options', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('poll_id')->unsigned()->index();
            // Options of a proposal are fixed and translated: they have a key.
            // Options of a choice are written by the author: they have a label.
            $table->string('key')->nullable();
            $table->string('label')->nullable();
            $table->integer('position')->unsigned()->default(0);
            $table->timestamps();
        });

        Schema::create('poll_votes', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('poll_id')->unsigned();
            $table->integer('poll_option_id')->unsigned()->index();
            $table->integer('user_id')->unsigned()->index();
            $table->text('reason')->nullable();
            $table->timestamps();
            // One vote per member and per poll; a new vote replaces the previous one.
            $table->unique(['poll_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('poll_votes');
        Schema::dropIfExists('poll_options');
        Schema::dropIfExists('polls');
    }
};
