<?php

include_once 'setting.inc.php';

$_lang['spookyapp'] = 'SpookyApp';
$_lang['spookyapp_menu_desc'] = 'Blog assistant: Topic Finder & Chunk Generator';
$_lang['spookyapp_intro_msg'] = 'You can select multiple items by holding Shift or Ctrl button.';

// -- Items (legacy) --
$_lang['spookyapp_items'] = 'Items';
$_lang['spookyapp_item_id'] = 'Id';
$_lang['spookyapp_item_name'] = 'Name';
$_lang['spookyapp_item_description'] = 'Description';
$_lang['spookyapp_item_active'] = 'Active';
$_lang['spookyapp_item_create'] = 'Create Item';
$_lang['spookyapp_item_update'] = 'Update Item';
$_lang['spookyapp_item_enable'] = 'Enable Item';
$_lang['spookyapp_items_enable'] = 'Enable Items';
$_lang['spookyapp_item_disable'] = 'Disable Item';
$_lang['spookyapp_items_disable'] = 'Disable Items';
$_lang['spookyapp_item_remove'] = 'Remove Item';
$_lang['spookyapp_items_remove'] = 'Remove Items';
$_lang['spookyapp_item_remove_confirm'] = 'Are you sure you want to remove this Item?';
$_lang['spookyapp_items_remove_confirm'] = 'Are you sure you want to remove this Items?';
$_lang['spookyapp_item_err_name'] = 'You must specify the name of Item.';
$_lang['spookyapp_item_err_ae'] = 'An Item already exists with that name.';
$_lang['spookyapp_item_err_nf'] = 'Item not found.';
$_lang['spookyapp_item_err_ns'] = 'Item not specified.';
$_lang['spookyapp_item_err_remove'] = 'An error occurred while trying to remove the Item.';
$_lang['spookyapp_item_err_save'] = 'An error occurred while trying to save the Item.';
$_lang['spookyapp_grid_search'] = 'Search';
$_lang['spookyapp_grid_actions'] = 'Actions';

// -- Mode Switcher --
$_lang['spookyapp.mode.topicfinder'] = 'What to Write';
$_lang['spookyapp.mode.chunkgenerator'] = 'What to Use';

// -- Topic Finder --
$_lang['spookyapp.topicfinder.title'] = 'Topic Finder';
$_lang['spookyapp.topic.score'] = 'Score';
$_lang['spookyapp.topic.title'] = 'Title';
$_lang['spookyapp.topic.source'] = 'Source';
$_lang['spookyapp.topic.category'] = 'Category';
$_lang['spookyapp.topic.status'] = 'Status';
$_lang['spookyapp.topic.published_at'] = 'Published';
$_lang['spookyapp.topic.save_draft'] = 'Save as Draft';
$_lang['spookyapp.topic.save_draft_confirm'] = 'Create a draft resource from';
$_lang['spookyapp.topic.draft_created'] = 'Draft created';
$_lang['spookyapp.topic.delete'] = 'Delete';
$_lang['spookyapp.topic.delete_confirm'] = 'Are you sure you want to delete';
$_lang['spookyapp.topic.deleted'] = 'Topic deleted';

// -- Filters --
$_lang['spookyapp.filters.title'] = 'Filters';
$_lang['spookyapp.filter.categories'] = 'Categories';
$_lang['spookyapp.filter.sources'] = 'Sources';
$_lang['spookyapp.filter.min_score'] = 'Min Score';
$_lang['spookyapp.filter.date_from'] = 'From';
$_lang['spookyapp.filter.date_to'] = 'To';
$_lang['spookyapp.filter.category'] = 'Category';
$_lang['spookyapp.filter.source'] = 'Source';
$_lang['spookyapp.filter.all'] = 'All';
$_lang['spookyapp.filters.applied'] = 'Filters applied';
$_lang['spookyapp.filters.reset'] = 'Filters reset';

// -- Buttons --
$_lang['spookyapp.btn.apply'] = 'Apply';
$_lang['spookyapp.btn.reset'] = 'Reset';
$_lang['spookyapp.btn.refresh'] = 'Refresh';
$_lang['spookyapp.btn.get_new_topics'] = 'Get New Topics';
$_lang['spookyapp.btn.rewrite_ai'] = 'Rewrite with AI';
$_lang['spookyapp.btn.save_draft'] = 'Save as Draft';
$_lang['spookyapp.btn.copy_url'] = 'Copy URL';
$_lang['spookyapp.btn.delete'] = 'Delete';
$_lang['spookyapp.btn.export_csv'] = 'Export to CSV';
$_lang['spookyapp.btn.settings'] = 'Settings';
$_lang['spookyapp.btn.fetch_topics'] = 'Fetch Topics';
$_lang['spookyapp.btn.cancel'] = 'Cancel';
$_lang['spookyapp.btn.search_news'] = 'Search News';
$_lang['spookyapp.btn.scoring'] = 'Recalculate Scoring';
$_lang['spookyapp.btn.generate_ideas'] = 'Generate Ideas';

// -- Details Panel --
$_lang['spookyapp.details.title'] = 'Topic Details';
$_lang['spookyapp.details.select_topic'] = 'Select a topic to view details';
$_lang['spookyapp.details.score'] = 'Score';
$_lang['spookyapp.details.published'] = 'Published';
$_lang['spookyapp.details.description'] = 'Description';
$_lang['spookyapp.details.no_description'] = 'No description available';
$_lang['spookyapp.details.url'] = 'Source URL';
$_lang['spookyapp.details.metadata'] = 'Metadata';
$_lang['spookyapp.details.cached_at'] = 'Cached';
$_lang['spookyapp.details.no_url'] = 'No URL available for this topic.';
$_lang['spookyapp.details.url_copied'] = 'URL copied to clipboard';

// -- Get Topics Window --
$_lang['spookyapp.get_topics.title'] = 'Get New Topics';
$_lang['spookyapp.get_topics.sources'] = 'Sources';
$_lang['spookyapp.get_topics.categories'] = 'Categories';
$_lang['spookyapp.get_topics.min_score'] = 'Minimum Score';
$_lang['spookyapp.get_topics.limit'] = 'Limit (max topics)';
$_lang['spookyapp.get_topics.loading'] = 'Fetching topics...';
$_lang['spookyapp.get_topics.select_source'] = 'Please select at least one source.';
$_lang['spookyapp.get_topics.fetched'] = 'Topics fetched';

// -- General --
$_lang['spookyapp.success'] = 'Success';
$_lang['spookyapp.error'] = 'Error';

// -- Chunk Generator --
$_lang['spookyapp.chunkgenerator.tab_movies'] = 'Movies';
$_lang['spookyapp.chunkgenerator.tab_tv'] = 'TV Shows';
$_lang['spookyapp.chunkgenerator.tab_person'] = 'Person';
$_lang['spookyapp.chunkgenerator.tab_games'] = 'Games';
$_lang['spookyapp.chunkgenerator.tab_devices'] = 'Devices';
$_lang['spookyapp.chunkgenerator.tab_sports'] = 'Sports';
$_lang['spookyapp.chunkgenerator.tab_products'] = 'Products';
$_lang['spookyapp.chunkgenerator.details'] = 'Details';
$_lang['spookyapp.chunkgenerator.no_results'] = 'No results. Use the search form above to find content.';
$_lang['spookyapp.chunkgenerator.col_title'] = 'Title';
$_lang['spookyapp.chunkgenerator.col_original_title'] = 'Original Title';
$_lang['spookyapp.chunkgenerator.col_year'] = 'Year';
$_lang['spookyapp.chunkgenerator.col_rating'] = 'Rating';
$_lang['spookyapp.chunkgenerator.col_votes'] = 'Votes';
$_lang['spookyapp.chunkgenerator.query'] = 'Search';
$_lang['spookyapp.chunkgenerator.query_empty'] = 'Enter search query...';
$_lang['spookyapp.chunkgenerator.year'] = 'Year';
$_lang['spookyapp.chunkgenerator.year_any'] = 'Any';
$_lang['spookyapp.chunkgenerator.search_btn'] = 'Search';
$_lang['spookyapp.chunkgenerator.clear_btn'] = 'Clear';
$_lang['spookyapp.chunkgenerator.subtype'] = 'Sub-type';
$_lang['spookyapp.chunkgenerator.load_options'] = 'Load:';
$_lang['spookyapp.chunkgenerator.reload'] = 'Reload';
$_lang['spookyapp.chunkgenerator.preview_chunk'] = 'Preview Chunk';
$_lang['spookyapp.chunkgenerator.save_to_db'] = 'Save to DB';
$_lang['spookyapp.chunkgenerator.copy_code'] = 'Copy Code';
$_lang['spookyapp.chunkgenerator.reset'] = 'Reset';
$_lang['spookyapp.chunkgenerator.select_item'] = 'Select an item from search results';
$_lang['spookyapp.chunkgenerator.dblclick_hint'] = 'Double-click or press "Details" button';
$_lang['spookyapp.chunkgenerator.loading_details'] = 'Loading details...';
$_lang['spookyapp.chunkgenerator.translate'] = 'Translate';
$_lang['spookyapp.chunkgenerator.voice_over'] = 'Voice Over';
$_lang['spookyapp.chunkgenerator.generate'] = 'Generate Chunk';
$_lang['spookyapp.chunkgenerator.opt_cast'] = 'Cast';
$_lang['spookyapp.chunkgenerator.opt_crew'] = 'Crew';
$_lang['spookyapp.chunkgenerator.opt_screenshots'] = 'Screenshots';
$_lang['spookyapp.chunkgenerator.opt_similar'] = 'Similar';
$_lang['spookyapp.chunkgenerator.opt_seasons'] = 'Seasons';
$_lang['spookyapp.chunkgenerator.opt_movies'] = 'Movies';
$_lang['spookyapp.chunkgenerator.opt_tv'] = 'TV Shows';
$_lang['spookyapp.chunkgenerator.opt_images'] = 'Images';
$_lang['spookyapp.chunkgenerator.opt_offers'] = 'Offers';

// -- Age Rating --
$_lang['spookyapp.chunkgenerator.age_rating'] = 'Age Rating';

// US MPAA / TV Parental Guidelines descriptions
$_lang['spookyapp.age_rating.G']     = 'G — General Audiences. All ages admitted.';
$_lang['spookyapp.age_rating.PG']    = 'PG — Parental Guidance Suggested. Suitable for ages 10+';
$_lang['spookyapp.age_rating.PG-13'] = 'PG-13 — Parents Strongly Cautioned. Suitable for ages 13+';
$_lang['spookyapp.age_rating.R']     = 'R — Restricted. Suitable for ages 17+';
$_lang['spookyapp.age_rating.NC-17'] = 'NC-17 — Adults Only. Ages 18+ only.';
$_lang['spookyapp.age_rating.NR']    = 'NR — Not Rated.';
// US TV Parental Guidelines
$_lang['spookyapp.age_rating.TV-Y']   = 'TV-Y — All Children.';
$_lang['spookyapp.age_rating.TV-Y7']  = 'TV-Y7 — Directed to Older Children, ages 7+';
$_lang['spookyapp.age_rating.TV-G']   = 'TV-G — General Audience.';
$_lang['spookyapp.age_rating.TV-PG']  = 'TV-PG — Parental Guidance Suggested, ages 10+';
$_lang['spookyapp.age_rating.TV-14']  = 'TV-14 — Parents Strongly Cautioned, ages 14+';
$_lang['spookyapp.age_rating.TV-MA'] = 'TV-MA — Mature Audience Only, ages 17+';