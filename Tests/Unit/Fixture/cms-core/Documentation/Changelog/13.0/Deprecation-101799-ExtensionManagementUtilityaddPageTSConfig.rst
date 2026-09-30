.. include:: /Includes.rst.txt

.. _deprecation-101799-1691145454:

=====================================================================
Deprecation: #101799 - ExtensionManagementUtility::addPageTSConfig()
=====================================================================

See :issue:`101799`

Description
===========

The method :php:`ExtensionManagementUtility::addPageTSConfig()` has been deprecated.

Impact
======

Extensions should place default page TSconfig in :file:`Configuration/page.tsconfig`
files instead.

Migration
=========

Add default page TSconfig to a :file:`Configuration/page.tsconfig` file within an
extension and remove calls to :php:`ExtensionManagementUtility::addPageTSConfig()`.


.. index:: LocalConfiguration, PHP-API, TSConfig, FullyScanned, ext:core
