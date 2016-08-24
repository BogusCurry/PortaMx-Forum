// *** pmxc_Editor class.
/*
 Kept for compatibility
 */
function pmxc_Editor(oOptions)
{
	this.opt = oOptions;

	var editor = $('#' + oOptions.sUniqueId);
	this.sUniqueId = this.opt.sUniqueId;
	this.bRichTextEnabled = true;
}

// Return the current text.
pmxc_Editor.prototype.getText = function(bPrepareEntities, bModeOverride)
{
	return $('#' + this.sUniqueId).data("sceditor").getText();
}

// Set the HTML content to be that of the text box - if we are in wysiwyg mode.
pmxc_Editor.prototype.doSubmit = function()
{}

// Populate the box with text.
pmxc_Editor.prototype.insertText = function(sText, bClear, bForceEntityReverse, iMoveCursorBack)
{
	$('#' + this.sUniqueId).data("sceditor").InsertText(sText.replace(/<br \/>/gi, ''), bClear);
}

// Start up the spellchecker!
pmxc_Editor.prototype.spellCheckStart = function()
{
	if (!spellCheck)
		return false;

	$('#' + this.sUniqueId).data("sceditor").storeLastState();
	// If we're in HTML mode we need to get the non-HTML text.
	$('#' + this.sUniqueId).data("sceditor").setTextMode();

	spellCheck(false, this.opt.sUniqueId);

	return true;
}
