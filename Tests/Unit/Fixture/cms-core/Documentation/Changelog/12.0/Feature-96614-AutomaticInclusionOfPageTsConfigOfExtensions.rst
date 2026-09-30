.. include:: /Includes.rst.txt

.. _feature-96614:

===================================================================
Feature: #96614 - Automatic inclusion of page TSconfig of extensions
===================================================================

See :issue:`96614`

Description
===========

A :file:`Configuration/page.tsconfig` file is included automatically, so calls to
:php:`ExtensionManagementUtility::addPageTSConfig()` are no longer needed.

.. index:: TSConfig, ext:core
