<?php

/**
 * A single file, as Scisr sees it.
 *
 * Used to track edits and then make the actual changes.
 */
class Scisr_File
{

    /**
     * The path to the file
     * @var string
     */
    public $filename;
    /**
     * Stores the pending changes to this file.
     * A 2D array by line number, then column number
     * @var array
     */
    public $changes = array();
    /**
     * A new filename.
     * If not null, indicates this file is to be renamed.
     * @var string|null
     */
    private $_newName = null;

    /**
     * Create a new Scisr_File
     * @param string $filename the path to the file
     */
    public function __construct($filename)
    {
        $this->filename = self::getAbsolutePath($filename);
    }

    /**
     * Calculate the absolute path for a file
     * @param string $filename a relative or absolute path to a file
     * @param string $currDir an absolute path to the current directory, which 
     * will be used as the base of a relative path. Defaults to the current 
     * working directory.
     * @return string the absolute path to the file
     */
    public static function getAbsolutePath($filename, $currDir=null)
    {
        // If it's not an absolute path already, calculate it from our current dir
        if ($filename{0} != '/') {
            if ($currDir === null) {
                $currDir = getcwd();
            }
            $filename = $currDir . '/' . $filename;
        }
        return self::normalizePath($filename);
    }

    protected static function normalizePath($path)
    {
        $pieces = explode('/', $path);
        // Filter out empty items
        $pieces = array_filter($pieces, create_function('$s', 'return ($s !== "");'));
        // array_filter left us with wonky keys, which will confuse array_splice, so rekey
        $pieces = array_values($pieces);
        // A for loop is ill-advised because we are changing the contents of the array
        while (true) {
            if ($i = array_search('.', $pieces)) {
                array_splice($pieces, $i, 1);
            } else if ($i = array_search('..', $pieces)) {
                array_splice($pieces, $i - 1, 2);
            } else {
                break;
            }
        }

        return '/' . implode('/', $pieces);
    }

    /**
     * Add a pending edit
     *
     * The edit will not actually be applied until you run {@link process()}.
     *
     * @param int $line the line number of the edit
     * @param int $column the column number where the edit begins
     * @param int $length length of the text to remove
     * @param string $replacement the text to replace the removed text with
     * @todo detect conflicting edits
     */
    public function addEdit($line, $column, $length, $replacement)
    {
        $this->changes[$line][$column] = array($length, $replacement);
    }

    /**
     * Set a pending file rename
     *
     * Will not actually be applied until you run {@link process()}.
     *
     * @param string $newName the new name for this file
     */
    public function rename($newName)
    {
        $this->_newName = self::getAbsolutePath($newName);
    }

    /**
     * Process all pending edits to the file
     */
    public function process()
    {
        // Sort by columns and then by lines
        foreach ($this->changes as $key => &$array) {
            ksort($array);
        }
        ksort($this->changes);

        // Get the file contents and open it for writing
        $contents = file($this->filename);
        $output = array();
        // Loop through the file contents, making changes
        foreach ($contents as $i => $line) {
            $lineNo = $i + 1;
            if (isset($this->changes[$lineNo])) {
                // Track the net column change caused by edits to this line so far
                $lineOffsetDelta = 0;
                // Track the (offset-adjusted) last column modified to prevent edit conflicts
                $lastChanged = 0;
                foreach ($this->changes[$lineNo] as $col => $edit) {
                    if ($col <= $lastChanged) {
                        // I don't expect this to ever happen unless a developer makes a mistake,
                        // so we'll just abort messily
                        $err = "We've encountered conflicting edit requests. Cannot continue.";
                        throw new Exception($err);
                    }
                    $col += $lineOffsetDelta;
                    $length = $edit[0];
                    $replacement = $edit[1];
                    // Update the net offset with the change caused by this edit
                    $lineOffsetDelta += strlen($replacement) - $length;
                    // Make the change
                    $line = substr_replace($line, $replacement, $col - 1, $length);
                    // Update to the last column this edit affected
                    $lastChanged = $col + $length - 1;
                }
            }
            // Save the resulting line to be written to the file
            $output[] = $line;
        }
        // Write all output to the file
        file_put_contents($this->filename, $output);

        // If there's a rename pending, do it
        if ($this->_newName !== null) {
            rename($this->filename, $this->_newName);
        }
    }
}
