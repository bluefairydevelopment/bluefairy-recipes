/**
 * A recipe's label in the editor pickers. REST's `title.rendered` is HTML
 * (`Mac &amp; Cheese`, `&#8217;`), and a ComboboxControl shows text, so it is
 * decoded first; an untitled recipe shows its id.
 *
 * @param {{id: number, title: {rendered: string}}} recipe The REST record.
 * @param {function(string): string}                decode `decodeEntities` from @wordpress/html-entities.
 * @return {string} The label.
 */
export function recipeLabel( recipe, decode ) {
	return decode( recipe.title.rendered || '' ) || String( recipe.id );
}
