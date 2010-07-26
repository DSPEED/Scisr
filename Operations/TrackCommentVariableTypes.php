<?php

/**
 * Tracks variable types specified in PHPDoc tags
 */
class Scisr_Operations_TrackCommentVariableTypes
    extends Scisr_Operations_AbstractTrackVariableTypeOperation
    implements PHP_CodeSniffer_Sniff
{
    protected function processVar($var, $commentPtr, $phpcsFile)
    {
        $varPtr = $phpcsFile->findNext(T_VARIABLE, $commentPtr);

        if ($varPtr === false) {
            return false;
        }

        $this->_variableTypes->setVariableType($varPtr, $var->getContent(), $phpcsFile, $var->getVarName());
    }

    protected function processParam($param, $commentPtr, $phpcsFile)
    {
        $tokens = $phpcsFile->getTokens();
        $funcPtr = $phpcsFile->findNext(T_FUNCTION, $commentPtr);

        if ($funcPtr === false) {
            return false;
        }

        // Find the bounds of the function argument list
        $funcOpenParen = $tokens[$funcPtr]['parenthesis_opener'];
        $funcCloseParen = $tokens[$funcPtr]['parenthesis_closer'];

        // Loop through the function arguments, looking for the given variable
        $varPtr = $funcOpenParen + 1;
        $varFound = false;
        while ($varPtr > $funcOpenParen && $varPtr < $funcCloseParen) {
            if ($tokens[$varPtr]['content'] == $param->getVarName()) {
                $varFound = true;
                break;
            }
            $varPtr++;
        }

        // If we didn't find the variable, just point to the comment instead
        if (!$varFound) {
            $varPtr = $commentPtr;
        }

        $this->_variableTypes->setVariableType($varPtr, $param->getType(), $phpcsFile, $param->getVarName(), $funcPtr);
    }

    protected function processReturn($return, $commentPtr, $phpcsFile)
    {
        $tokens = $phpcsFile->getTokens();
        $funcPtr = $phpcsFile->findNext(T_FUNCTION, $commentPtr);
        if ($funcPtr === false) {
            return;
        }

        $funcNamePtr = $phpcsFile->findNext(T_STRING, $funcPtr);
        if ($funcNamePtr === false) {
            return;
        }

        // We identify this as a function type by prepending a '*' to the name
        $funcName = '*' . $tokens[$funcNamePtr]['content'];
        $this->_variableTypes->setVariableType($funcNamePtr, $return->getValue(), $phpcsFile, $funcName);
    }

}
