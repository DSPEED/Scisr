<?php

/**
 * An operation to change the name of a class
 */
class Scisr_Operations_RenameClass implements PHP_CodeSniffer_Sniff
{

    public $oldName;
    public $newName;

    public function __construct($oldName, $newName)
    {
        $this->oldName = $oldName;
        $this->newName = $newName;
    }

    public function register()
    {
        return array(
            T_CLASS,
            T_NEW,
            T_EXTENDS,
            T_PAAMAYIM_NEKUDOTAYIM,
            T_FUNCTION,
        );
    }

    public function process(PHP_CodeSniffer_File $phpcsFile, $stackPtr)
    {
        $tokens = $phpcsFile->getTokens();

        // Find the actual name of the class

        if ($tokens[$stackPtr]['code'] == T_FUNCTION) {
            // Get information about the function parameters
            $paramsInfo = $phpcsFile->getMethodParameters($stackPtr);
            // Check each param to see if it has a type hint we need to change
            foreach ($paramsInfo as $paramInfo) {
                if (isset($paramInfo['type_hint'])) {
                    $classNamePtr = $paramInfo['type_hint'];
                    $this->checkClassNamePtr($classNamePtr, $phpcsFile);
                }
            }
        } else if ($tokens[$stackPtr]['code'] == T_PAAMAYIM_NEKUDOTAYIM) {
            $classNamePtr = $phpcsFile->findPrevious(T_STRING, $stackPtr);
            $this->checkClassNamePtr($classNamePtr, $phpcsFile);
        } else {
            $classNamePtr = $phpcsFile->findNext(T_STRING, $stackPtr);
            $this->checkClassNamePtr($classNamePtr, $phpcsFile);
        }
    }

    private function checkClassNamePtr($classNamePtr, $phpcsFile)
    {
        $tokens = $phpcsFile->getTokens();
        $tokenInfo = $tokens[$classNamePtr];
        $className = $tokenInfo['content'];
        // If it's the name we're looking for, register it
        if ($className == $this->oldName) {
            Scisr_ChangeRegistry::addChange(
                $phpcsFile->getFileName(),
                $tokenInfo['line'],
                $tokenInfo['column'],
                strlen($tokenInfo['content']),
                $this->newName
            );
        }
    }
}
