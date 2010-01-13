<?php

/**
 * An operation to change the name of a class
 */
class Scisr_Operations_ChangeFile implements PHP_CodeSniffer_Sniff
{

    public $oldName;
    public $newName;

    public function __construct($oldName, $newName)
    {
        $this->oldName = Scisr_File::getAbsolutePath($oldName);
        $this->newName = Scisr_File::getAbsolutePath($newName);
    }

    public function register()
    {
        return array(
            T_INCLUDE,
            T_INCLUDE_ONCE,
            T_REQUIRE,
            T_REQUIRE_ONCE,
            );
    }

    public function process(PHP_CodeSniffer_File $phpcsFile, $stackPtr)
    {
        $tokens = $phpcsFile->getTokens();
        // Find the arguments to this call
        $nextPtr = $phpcsFile->findNext(T_WHITESPACE, $stackPtr + 1, null, true);
        $nextToken = $tokens[$nextPtr];
        // We have to account for the possibility of these calls not having parentheses
        if ($nextToken['code'] == T_OPEN_PARENTHESIS) {
            $strTokens = $this->getStringTokens($tokens, $nextPtr + 1, $nextToken['parenthesis_closer']);
        } else {
            $endStmtPtr = $phpcsFile->findNext(T_SEMICOLON, $stackPtr);
            $strTokens = $this->getStringTokens($tokens, $nextPtr, $endStmtPtr);
        }
        // Decide what to do with the results
        if (!$strTokens) {
            // We failed or didn't get any tokens, quit
            return false;
        } else if (count($strTokens) == 1) {
            // If there's only one token, we can go ahead and make the change confidently
            $fileToken = $strTokens[0];
            $fileStr = $fileToken['content'];
            $length = strlen($fileStr);
            $line = $fileToken['line'];
            $column = $fileToken['column'];
            // Strip the quotes
            $quote = $fileStr{0};
            $fileStr = substr($fileStr, 1, -1);
            $intact = true;
        } else {
            // Otherwise we'll be more cautious - but if aggressive, we'll mush the
            // string tokens into one big string. This could get messy.
            $firstToken = $strTokens[0];
            $quote = $firstToken['content']{0};
            $column = $firstToken['column'];
            $line = $firstToken['line'];
            $lastToken = $strTokens[count($strTokens) - 1];
            $length = $lastToken['column'] + strlen($lastToken['content']) - $column;
            $fileStr = '';
            foreach ($strTokens as $str) {
                $fileStr .= substr($str['content'], 1, -1);
            }
            $intact = false;
        }
        // If the filename matches, register it
        $base = $this->matchPaths($this->oldName, $fileStr, $phpcsFile);
        if ($base !== false) {
            $newName = $this->pathRelativeTo($this->newName, $base);
            Scisr_ChangeRegistry::addChange(
                $phpcsFile->getFileName(),
                $line,
                $column,
                $length,
                $quote . $newName . $quote,
                !$intact
            );
        }
    }

    /**
     * Parse a section of tokens, looking for string tokens.
     *
     * Filters out whitespace and string concats. Quits in failure if any
     * other kind of tokens are encountered.
     *
     * @param array $tokens the array of tokens
     * @param int $startPtr the stack pointer at which to begin parsing
     * @param int $endPtr the stack pointer (exclusive) at which to halt
     * @return array|null an array of all the string tokens, or false if we
     * did not succeed.
     */
    protected function getStringTokens($tokens, $startPtr, $endPtr)
    {
        $currPtr = $startPtr;
        $result = array();
        while ($currPtr < $endPtr) {
            $currToken = $tokens[$currPtr];
            if ($currToken['code'] == T_CONSTANT_ENCAPSED_STRING) {
                $result[] = $currToken;
            } else if (!in_array($currToken['code'], array(T_STRING_CONCAT, T_WHITESPACE))) {
                // We've hit something we can't handle, fail
                return false;
            }
            $currPtr++;
        }
        return $result;
    }

    /**
     * See if paths match satisfactorily
     * @param string $expectedPath an absolute path to target file
     * @param string $actualPath the absolute or relative path that was found
     * @param PHP_CodeSniffer_File $phpcsFile the file where $actualpath was 
     * found
     * @return string|bool false if the paths do not match. If they do match,
     * returns the base, if any, that is not explicitly defined by $actualPath.
     * Since this method may return the empty string on success, strict type
     * comparison must be used.
     */
    public function matchPaths($expectedPath, $actualPath, $phpcsFile)
    {
        // If it's an absolute path, it must match exactly
        if ($actualPath{0} == '/') {
            return (($expectedPath == $actualPath) ? '' : false);
        }
        // A simple test: see if the actual matches the end of the expected path
        if (strstr($expectedPath, $actualPath) == $actualPath) {
            $base = substr($expectedPath, 0, strpos($expectedPath, $actualPath));
            return $base;
        }
        return false;
    }

    /**
     * Get the path relative to a given base
     * @param string $path an absolute path to a file
     * @param string $basePath an absolute path serving as the base
     * @return string a relative path describing $path in relation to $basePath
     */
    public function pathRelativeTo($path, $basePath)
    {
        // Check for the implied ending slash
        if ($basePath != '' && substr($basePath, -1) != '/') {
            $basePath .= '/';
        }
        // Break up the paths and step through them
        $pathChunks = explode('/', $path);
        $baseChunks = explode('/', $basePath);
        $pos = 0;
        foreach ($baseChunks as $i => $baseChunk) {
            $pathChunk = $pathChunks[$i];
            $pos = $i;
            if ($baseChunk != $pathChunk) {
                break;
            }
        }
        // Now get the path from the point where it no longer matched the base
        $newPathChunks = array_slice($pathChunks, $pos);
        return implode('/', $newPathChunks);
    }

}
