..  include:: /Includes.rst.txt

..  _deprecation-106393-1742454612:

========================================================
Deprecation: #106393 - Various methods in BackendUtility
========================================================

See :issue:`106393`

Description
===========

Several methods of :php:`BackendUtility` have been deprecated.

Impact
======

Calling any of the mentioned methods now triggers a deprecation-level log
entry and will stop working in TYPO3
v15.0.

Migration
=========

Use the corresponding Schema API methods directly in your code.

getItemLabel
------------

..  code-block:: php

    // Before
    return BackendUtility::getItemLabel('pages', 'title');

    // After
    return $this->schemaFactory->get('pages')->getField('title')->getLabel();

resolveFileReferences
---------------------

No substitution is available.

..  index:: TCA, FullyScanned, ext:core
