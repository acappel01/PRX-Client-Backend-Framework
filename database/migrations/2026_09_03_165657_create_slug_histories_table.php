<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every slug a record used to have, so a renamed URL redirects instead of 404s.
 *
 * WHY THIS EXISTS. A slug is a public URL. Renaming one breaks every inbound
 * link, bookmark, ad, and indexed result pointing at the old name, and the
 * frontend cannot help: it keeps no registry of valid slugs, it asks this API
 * and 404s when the answer is "no such record". Without a record of the former
 * name there is nothing to redirect TO.
 *
 * WHY IT STORES THE SUBJECT AND NOT A TARGET SLUG. Rename a -> b -> c and a
 * table of slug pairs gives you a redirect chain, which costs a round trip per
 * hop and rots the moment a middle entry is deleted. Pointing at the RECORD
 * means every historical slug resolves to whatever that record is called right
 * now, in one hop, forever.
 *
 * WHY UNIQUE ON (subject_type, slug) AND NOT (subject_type, subject_id, slug).
 * A slug must identify at most one record of a type — that is what makes it a
 * URL. Two records claiming the same historical slug would be an ambiguous
 * redirect, so the database refuses it rather than letting resolution pick
 * arbitrarily. The trait handles the legitimate case (a record reclaiming a
 * slug another record abandoned) by deleting the stale history row.
 *
 * A LIVE SLUG ALWAYS WINS. Resolution looks here only after a live lookup
 * misses, so a new record taking an old name serves its own page — history
 * never shadows the present.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slug_histories', function (Blueprint $table): void {
            $table->id();

            // Polymorphic on purpose: products, packages, pages and knowledge-base
            // monographs all have public URLs, and every future one will too.
            // A per-model table would be the same schema four times over.
            $table->morphs('subject');

            $table->string('slug');

            // When the record stopped using it. Kept for the operator's benefit —
            // "this URL changed three months ago" is the context that decides
            // whether a redirect is still earning its keep.
            $table->timestamps();

            $table->unique(['subject_type', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slug_histories');
    }
};
