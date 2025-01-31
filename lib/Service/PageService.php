<?php

namespace OCA\Collectives\Service;

use OCA\Collectives\Db\Page;
use OCA\Collectives\Db\PageMapper;
use OCA\Collectives\Fs\NodeHelper;
use OCA\Collectives\Fs\UserFolderHelper;
use OCA\Collectives\Model\CollectiveInfo;
use OCA\Collectives\Model\PageInfo;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\InvalidPathException;
use OCP\Files\NotPermittedException as FilesNotPermittedException;
use OCP\Files\NotFoundException as FilesNotFoundException;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Lock\LockedException;

class PageService {
	private const DEFAULT_PAGE_TITLE = 'New Page';

	private PageMapper $pageMapper;
	private NodeHelper $nodeHelper;
	private CollectiveServiceBase $collectiveService;
	private UserFolderHelper $userFolderHelper;
	private IUserManager $userManager;
	private IConfig $config;
	private ?CollectiveInfo $collectiveInfo = null;

	/**
	 * @param PageMapper            $pageMapper
	 * @param NodeHelper            $nodeHelper
	 * @param CollectiveServiceBase $collectiveService
	 * @param UserFolderHelper      $userFolderHelper
	 * @param IUserManager          $userManager
	 * @param IConfig               $config
	 */
	public function __construct(PageMapper $pageMapper,
								NodeHelper $nodeHelper,
								CollectiveServiceBase $collectiveService,
								UserFolderHelper $userFolderHelper,
								IUserManager $userManager,
								IConfig  $config) {
		$this->pageMapper = $pageMapper;
		$this->nodeHelper = $nodeHelper;
		$this->collectiveService = $collectiveService;
		$this->userFolderHelper = $userFolderHelper;
		$this->userManager = $userManager;
		$this->config = $config;
	}


	/**
	 * @param int    $collectiveId
	 * @param string $userId
	 *
	 * @return CollectiveInfo
	 * @throws MissingDependencyException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	private function getCollectiveInfo(int $collectiveId, string $userId): CollectiveInfo {
		if (null === $this->collectiveInfo || $this->collectiveInfo->getId() !== $collectiveId) {
			$this->collectiveInfo = $this->collectiveService->getCollectiveInfo($collectiveId, $userId);
		}

		return $this->collectiveInfo;
	}

	/**
	 * @param int    $collectiveId
	 * @param string $userId
	 *
	 * @return void
	 * @throws MissingDependencyException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	private function verifyEditPermissions(int $collectiveId, string $userId): void {
		if (!$this->getCollectiveInfo($collectiveId, $userId)->canEdit()) {
			throw new NotPermittedException('Not allowed to edit collective');
		}
	}

	/**
	 * @param int    $collectiveId
	 * @param string $userId
	 *
	 * @return Folder
	 * @throws FilesNotFoundException
	 * @throws MissingDependencyException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	public function getCollectiveFolder(int $collectiveId, string $userId): Folder {
		$collectiveName = $this->getCollectiveInfo($collectiveId, $userId)->getName();
		try {
			$folder = $this->userFolderHelper->get($userId)->get($collectiveName);
		} catch (FilesNotFoundException $e) {
			// Workaround issue #332
			\OC_Util::setupFS($userId);
			$folder = $this->userFolderHelper->get($userId)->get($collectiveName);
		}

		if (!($folder instanceof Folder)) {
			throw new FilesNotFoundException('Folder not found for collective ' . $collectiveId);
		}

		return $folder;
	}

	/**
	 * @param int    $collectiveId
	 * @param int    $fileId
	 * @param string $userId
	 *
	 * @return Folder
	 * @throws MissingDependencyException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	public function getFolder(int $collectiveId, int $fileId, string $userId): Folder {
		$collectiveFolder = $this->getCollectiveFolder($collectiveId, $userId);
		if ($fileId === 0) {
			return $collectiveFolder;
		}

		$file = $this->nodeHelper->getFileById($collectiveFolder, $fileId);
		if (!($file->getParent() instanceof Folder)) {
			throw new NotFoundException('Error getting parent folder for file ' . $fileId . ' in collective ' . $collectiveId);
		}

		return $file->getParent();
	}

	/**
	 * @param File $file
	 *
	 * @return int
	 * @throws NotFoundException
	 */
	public function getParentPageId(File $file): int {
		try {
			if (self::isLandingPage($file)) {
				// Return `0` for landing page
				return 0;
			}

			if (self::isIndexPage($file)) {
				// Go down two levels if index page but not landing page
				return $this->getIndexPageFile($file->getParent()->getParent())->getId();
			}

			return $this->getIndexPageFile($file->getParent())->getId();
		} catch (InvalidPathException | FilesNotFoundException $e) {
			throw new NotFoundException($e->getMessage(), 0, $e);
		}
	}

	/**
	 * @param File $file
	 *
	 * @return PageInfo
	 * @throws NotFoundException
	 */
	private function getPageByFile(File $file): PageInfo {
		$pageInfo = new PageInfo();
		try {
			$page = $this->pageMapper->findByFileId($file->getId());
		} catch (InvalidPathException | FilesNotFoundException $e) {
			throw new NotFoundException($e->getMessage(), 0, $e);
		}
		$lastUserId = ($page !== null) ? $page->getLastUserId() : null;
		$emoji = ($page !== null) ? $page->getEmoji() : null;
		$subpageOrder = ($page !== null) ? $page->getSubpageOrder() : null;
		try {
			$pageInfo->fromFile($file,
				$this->getParentPageId($file),
				$lastUserId,
				$lastUserId ? $this->userManager->get($lastUserId)->getDisplayName() : null,
				$emoji,
				$subpageOrder);
		} catch (FilesNotFoundException | InvalidPathException $e) {
			throw new NotFoundException($e->getMessage(), 0, $e);
		}

		return $pageInfo;
	}

	/**
	 * @param int         $fileId
	 * @param string      $userId
	 * @param string|null $emoji
	 * @param string|null $subpageOrder
	 */
	private function updatePage(int $fileId, string $userId, ?string $emoji = null, ?string $subpageOrder = null): void {
		$page = new Page();
		$page->setFileId($fileId);
		$page->setLastUserId($userId);
		if ($emoji) {
			$page->setEmoji($emoji);
		}
		if ($subpageOrder) {
			$page->setSubpageOrder($subpageOrder);
		}
		$this->pageMapper->updateOrInsert($page);
	}

	/**
	 * @param Folder $folder
	 * @param string $filename
	 * @param string $userId
	 *
	 * @return PageInfo
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	private function newPage(Folder $folder, string $filename, string $userId): PageInfo {
		$hasTemplate = self::folderHasSubPage($folder, PageInfo::TEMPLATE_PAGE_TITLE);
		try {
			if ($hasTemplate === 1) {
				$template = $folder->get(PageInfo::TEMPLATE_PAGE_TITLE . PageInfo::SUFFIX);
				$newFile = $template->copy($folder->getPath() . '/' . $filename . PageInfo::SUFFIX);
			} elseif ($hasTemplate === 2) {
				$template = $folder->get(PageInfo::TEMPLATE_PAGE_TITLE);
				$newFolder = $template->copy($folder->getPath() . '/' . $filename);
				if ($newFolder instanceof Folder) {
					$newFile = $newFolder->get(PageInfo::INDEX_PAGE_TITLE . PageInfo::SUFFIX);
				} else {
					throw new NotFoundException('Failed to get Template folder');
				}
			} else {
				$newFile = $folder->newFile($filename . PageInfo::SUFFIX);
			}
		} catch (FilesNotFoundException $e) {
			throw new NotFoundException($e->getMessage(), 0, $e);
		} catch (FilesNotPermittedException $e) {
			throw new NotPermittedException($e->getMessage(), 0, $e);
		}

		$pageInfo = new PageInfo();
		try {
			$pageInfo->fromFile($newFile,
				$this->getParentPageId($newFile),
				$userId,
				$userId ? $this->userManager->get($userId)->getDisplayName() : null);
			$this->updatePage($newFile->getId(), $userId);
		} catch (FilesNotFoundException | InvalidPathException $e) {
			throw new NotFoundException($e->getMessage(), 0, $e);
		}

		return $pageInfo;
	}

	/**
	 * @param File $file
	 *
	 * @return Folder
	 * @throws NotPermittedException
	 */
	public function initSubFolder(File $file): Folder {
		$folder = $file->getParent();
		if (self::isIndexPage($file)) {
			return $folder;
		}

		try {
			$folderName = NodeHelper::generateFilename($folder, basename($file->getName(), PageInfo::SUFFIX));
			$subFolder = $folder->newFolder($folderName);
			$file->move($subFolder->getPath() . '/' . PageInfo::INDEX_PAGE_TITLE . PageInfo::SUFFIX);
		} catch (InvalidPathException | FilesNotFoundException | FilesNotPermittedException | LockedException $e) {
			throw new NotPermittedException($e->getMessage(), 0, $e);
		}
		return $subFolder;
	}

	/**
	 * @param Folder $folder
	 *
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	private function revertSubFolders(Folder $folder): void {
		try {
			foreach ($folder->getDirectoryListing() as $node) {
				if ($node instanceof Folder) {
					$this->revertSubFolders($node);
				} elseif ($node instanceof File) {
					// Move index page without subpages into the parent folder (if's not the landing page)
					if (self::isIndexPage($node) && !self::isLandingPage($node) && !$this->pageHasOtherContent($node)) {
						$filename = NodeHelper::generateFilename($folder, $folder->getName(), PageInfo::SUFFIX);
						$node->move($folder->getParent()->getPath() . '/' . $filename . PageInfo::SUFFIX);
						$folder->delete();
						break;
					}
				}
			}
		} catch (FilesNotFoundException | InvalidPathException $e) {
			throw new NotFoundException($e->getMessage(), 0, $e);
		} catch (FilesNotPermittedException | LockedException $e) {
			throw new NotPermittedException($e->getMessage(), 0, $e);
		}
	}

	/**
	 * @param File $file
	 *
	 * @return bool
	 */
	public static function isPage(File $file): bool {
		$name = $file->getName();
		$length = strlen(PageInfo::SUFFIX);
		return (substr($name, -$length) === PageInfo::SUFFIX);
	}

	/**
	 * @param File $file
	 *
	 * @return bool
	 */
	public static function isLandingPage(File $file): bool {
		$internalPath = $file->getInternalPath();
		return ($internalPath === PageInfo::INDEX_PAGE_TITLE . PageInfo::SUFFIX);
	}

	/**
	 * @param File $file
	 *
	 * @return bool
	 */
	public static function isIndexPage(File $file): bool {
		$name = $file->getName();
		return ($name === PageInfo::INDEX_PAGE_TITLE . PageInfo::SUFFIX);
	}

	/**
	 * @param Folder $folder
	 *
	 * @return File
	 * @throws NotFoundException
	 */
	private function getIndexPageFile(Folder $folder): File {
		try {
			$file = $folder->get(PageInfo::INDEX_PAGE_TITLE . PageInfo::SUFFIX);
		} catch (FilesNotFoundException $e) {
			throw new NotFoundException($e->getMessage(), 0, $e);
		}

		if (!($file instanceof File)) {
			throw new NotFoundException('Failed to get index page');
		}

		return $file;
	}

	/**
	 * @param File $file
	 *
	 * @return bool
	 */
	public function pageHasOtherContent(File $file): bool {
		try {
			foreach ($file->getParent()->getDirectoryListing() as $node) {
				if ($node instanceof File &&
					self::isPage($node) &&
					!self::isIndexPage($node)) {
					return true;
				}
				if ($node->getName() !== PageInfo::INDEX_PAGE_TITLE . PageInfo::SUFFIX) {
					return true;
				}
			}
		} catch (FilesNotFoundException $e) {
		}

		return false;
	}

	/**
	 * @param Folder $folder
	 *
	 * @return bool
	 */
	public static function folderHasSubPages(Folder $folder): bool {
		try {
			foreach ($folder->getDirectoryListing() as $node) {
				if ($node instanceof File &&
					self::isPage($node) &&
					!self::isIndexPage($node)) {
					return true;
				}

				if ($node instanceof Folder) {
					return self::folderHasSubPages($node);
				}
			}
		} catch (FilesNotFoundException $e) {
		}

		return false;
	}

	/**
	 * @param Folder $folder
	 * @param string $title
	 *
	 * @return int
	 */
	public static function folderHasSubPage(Folder $folder, string $title): int {
		try {
			foreach ($folder->getDirectoryListing() as $node) {
				if ($node instanceof File &&
					strcmp($node->getName(), $title . PageInfo::SUFFIX) === 0) {
					return 1;
				}

				if ($node instanceof Folder &&
					strcmp($node->getName(), $title) === 0 &&
					$node->nodeExists(PageInfo::INDEX_PAGE_TITLE . PageInfo::SUFFIX)) {
					return 2;
				}
			}
		} catch (FilesNotFoundException $e) {
		}

		return 0;
	}

	/**
	 * @param Folder $folder
	 * @param string $userId
	 *
	 * @return array
	 * @throws FilesNotFoundException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	public function recurseFolder(Folder $folder, string $userId): array {
		// Find index page or create it if we have subpages, but it doesn't exist
		try {
			$indexPage = $this->getPageByFile($this->getIndexPageFile($folder));
		} catch (NotFoundException $e) {
			if (!self::folderHasSubPages($folder)) {
				return [];
			}
			$indexPage = $this->newPage($folder, PageInfo::INDEX_PAGE_TITLE, $userId);
		}
		$pageInfos = [$indexPage];

		// Add subpages and recurse over subfolders
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof File && self::isPage($node) && !self::isIndexPage($node)) {
				$pageInfos[] = $this->getPageByFile($node);
			} elseif ($node instanceof Folder) {
				array_push($pageInfos, ...$this->recurseFolder($node, $userId));
			}
		}

		return $pageInfos;
	}

	/**
	 * @param int    $collectiveId
	 * @param string $userId
	 *
	 * @return PageInfo[]
	 * @throws MissingDependencyException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	public function findAll(int $collectiveId, string $userId): array {
		$folder = $this->getCollectiveFolder($collectiveId, $userId);
		try {
			return $this->recurseFolder($folder, $userId);
		} catch (NotPermittedException $e) {
			throw new NotFoundException($e->getMessage(), 0, $e);
		}
	}

	/**
	 * @param int    $collectiveId
	 * @param string $search
	 * @param string $userId
	 *
	 * @return PageInfo[]
	 * @throws MissingDependencyException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	public function findByString(int $collectiveId, string $search, string $userId): array {
		$allPageInfos = $this->findAll($collectiveId, $userId);
		$pageInfos = [];
		foreach ($allPageInfos as $pageInfo) {
			if (stripos($pageInfo->getTitle(), $search) === false) {
				continue;
			}
			$pageInfos[] = $pageInfo;
		}

		return $pageInfos;
	}

	/**
	 * @param int    $collectiveId
	 * @param int    $parentId
	 * @param int    $id
	 * @param string $userId
	 *
	 * @return PageInfo
	 * @throws MissingDependencyException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	public function find(int $collectiveId, int $parentId, int $id, string $userId): PageInfo {
		$folder = $this->getFolder($collectiveId, $parentId, $userId);
		return $this->getPageByFile($this->nodeHelper->getFileById($folder, $id));
	}

	/**
	 * @param int $collectiveId
	 * @param File $file
	 * @param string $userId
	 * @return PageInfo
	 * @throws FilesNotFoundException
	 * @throws InvalidPathException
	 * @throws MissingDependencyException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	public function findByFile(int $collectiveId, File $file, string $userId): PageInfo {
		return $this->find($collectiveId, $this->getParentPageId($file), $file->getId(), $userId);
	}

	/**
	 * @param int    $collectiveId
	 * @param int    $parentId
	 * @param string $title
	 * @param string $userId
	 *
	 * @return PageInfo
	 * @throws MissingDependencyException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	public function create(int $collectiveId, int $parentId, string $title, string $userId): PageInfo {
		$this->verifyEditPermissions($collectiveId, $userId);
		$folder = $this->getFolder($collectiveId, $parentId, $userId);
		$parentFile = $this->nodeHelper->getFileById($folder, $parentId);
		$folder = $this->initSubFolder($parentFile);
		$safeTitle = $this->nodeHelper->sanitiseFilename($title, self::DEFAULT_PAGE_TITLE);
		$filename = NodeHelper::generateFilename($folder, $safeTitle, PageInfo::SUFFIX);

		return $this->newPage($folder, $filename, $userId);
	}

	/**
	 * @param int    $collectiveId
	 * @param int    $parentId
	 * @param int    $id
	 * @param string $userId
	 *
	 * @return PageInfo
	 * @throws MissingDependencyException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	public function touch(int $collectiveId, int $parentId, int $id, string $userId): PageInfo {
		$this->verifyEditPermissions($collectiveId, $userId);
		$folder = $this->getFolder($collectiveId, $parentId, $userId);
		$file = $this->nodeHelper->getFileById($folder, $id);
		$pageInfo = $this->getPageByFile($file);
		$pageInfo->setLastUserId($userId);
		$pageInfo->setLastUserDisplayName($userId ? $this->userManager->get($userId)->getDisplayName() : null);
		$this->updatePage($pageInfo->getId(), $userId);
		return $pageInfo;
	}

	/**
	 * @param Folder $collectiveFolder
	 * @param int    $ancestorId
	 * @param int    $descendantId
	 *
	 * @return bool
	 * @throws NotFoundException
	 */
	private function isAncestorOf(Folder $collectiveFolder, int $pageId, int $targetId): bool {
		$targetFile = $this->nodeHelper->getFileById($collectiveFolder, $targetId);
		if (self::isLandingPage($targetFile)) {
			return false;
		}

		$targetParentPageId = $this->getParentPageId($targetFile);
		if ($pageId === $targetParentPageId) {
			return true;
		}

		return $this->isAncestorOf($collectiveFolder, $pageId, $targetParentPageId);
	}

	/**
	 * @param Folder      $collectiveFolder
	 * @param int         $parentId
	 * @param File        $file
	 * @param string|null $title
	 *
	 * @return bool
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	private function renamePage(Folder $collectiveFolder, int $parentId, File $file, ?string $title): bool {
		// Do not allow to move the landing page
		if (self::isLandingPage($file)) {
			throw new NotPermittedException('Not allowed to rename landing page');
		}

		// Do not allow to move a page to itself
		try {
			if ($parentId === $file->getId()) {
				throw new NotPermittedException('Not allowed to move a page to itself');
			}
		} catch (InvalidPathException | FilesNotFoundException $e) {
			throw new NotFoundException($e->getMessage(), 0, $e);
		}

		// Do not allow to move a page to a subpage of itself
		if ($this->isAncestorOf($collectiveFolder, $file->getId(), $parentId)) {
			throw new NotPermittedException('Not allowed to move a page to a subpage of itself');
		}

		$moveFolder = false;
		if ($parentId !== $this->getParentPageId($file)) {
			$newFolder = $this->initSubFolder($this->nodeHelper->getFileById($collectiveFolder, $parentId));
			$moveFolder = true;
		} else {
			$newFolder = $this->nodeHelper->getFileById($collectiveFolder, $parentId)->getParent();
		}

		// If processing an index page, then rename the parent folder, otherwise the file itself
		$node = self::isIndexPage($file) ? $file->getParent() : $file;
		$suffix = self::isIndexPage($file) ? '' : PageInfo::SUFFIX;
		if ($title) {
			$safeTitle = $this->nodeHelper->sanitiseFilename($title, self::DEFAULT_PAGE_TITLE);
			$newSafeName = $safeTitle . $suffix;
			$newFileName = NodeHelper::generateFilename($newFolder, $safeTitle, PageInfo::SUFFIX);
		} else {
			$newSafeName = $node->getName();
			$newFileName = basename($node->getName(), $suffix);
		}

		// Neither path nor title changed, nothing to do
		if (!$moveFolder && $newSafeName === $node->getName()) {
			return false;
		}

		try {
			$node->move($newFolder->getPath() . '/' . $newFileName . $suffix);
		} catch (InvalidPathException | FilesNotFoundException | LockedException $e) {
			throw new NotFoundException($e->getMessage(), 0, $e);
		} catch (FilesNotPermittedException $e) {
			throw new NotPermittedException($e->getMessage(), 0, $e);
		}

		return true;
	}

	/**
	 * @param int         $collectiveId
	 * @param int         $parentId
	 * @param int         $id
	 * @param string|null $title
	 * @param string      $userId
	 *
	 * @return PageInfo
	 * @throws FilesNotFoundException
	 * @throws MissingDependencyException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	public function rename(int $collectiveId, int $parentId, int $id, ?string $title, string $userId): PageInfo {
		$this->verifyEditPermissions($collectiveId, $userId);
		$collectiveFolder = $this->getCollectiveFolder($collectiveId, $userId);
		$file = $this->nodeHelper->getFileById($collectiveFolder, $id);
		if ($this->renamePage($collectiveFolder, $parentId, $file, $title)) {
			// Refresh the file after it has been renamed
			$file = $this->nodeHelper->getFileById($collectiveFolder, $id);
		}
		try {
			$this->updatePage($file->getId(), $userId);
		} catch (InvalidPathException | FilesNotFoundException $e) {
			throw new NotFoundException($e->getMessage(), 0, $e);
		}

		$this->revertSubFolders($collectiveFolder);
		return $this->getPageByFile($file);
	}

	/**
	 * @param int         $collectiveId
	 * @param int         $parentId
	 * @param int         $id
	 * @param string|null $emoji
	 * @param string      $userId
	 *
	 * @return PageInfo
	 * @throws MissingDependencyException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	public function setEmoji(int $collectiveId, int $parentId, int $id, ?string $emoji, string $userId): PageInfo {
		$this->verifyEditPermissions($collectiveId, $userId);
		$folder = $this->getFolder($collectiveId, $parentId, $userId);
		$file = $this->nodeHelper->getFileById($folder, $id);
		$pageInfo = $this->getPageByFile($file);
		$pageInfo->setLastUserId($userId);
		$pageInfo->setLastUserDisplayName($userId ? $this->userManager->get($userId)->getDisplayName() : null);
		$pageInfo->setEmoji($emoji);
		$this->updatePage($pageInfo->getId(), $userId, $emoji);
		return $pageInfo;
	}

	/**
	 * @param string|null $subpageOrder
	 *
	 * @return void
	 * @throws NotPermittedException
	 */
	public function verifySubpageOrder(?string $subpageOrder): void {
		if ($subpageOrder) {
			try {
				$subpageOrderDecoded = json_decode($subpageOrder, true, 512, JSON_THROW_ON_ERROR);
				if (!is_array($subpageOrderDecoded)) {
					throw new NotPermittedException();
				}
				foreach ($subpageOrderDecoded as $pageId) {
					if (!is_int($pageId)) {
						throw new NotPermittedException();
					}
				}
			} catch (\JsonException | NotPermittedException $e) {
				throw new NotPermittedException('Invalid format of subpage order');
			}
		}
	}

	/**
	 * @param int         $collectiveId
	 * @param int         $parentId
	 * @param int         $id
	 * @param string|null $subpageOrder
	 * @param string      $userId
	 *
	 * @return PageInfo
	 * @throws MissingDependencyException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	public function setSubpageOrder(int $collectiveId, int $parentId, int $id, ?string $subpageOrder, string $userId): PageInfo {
		$this->verifyEditPermissions($collectiveId, $userId);
		$folder = $this->getFolder($collectiveId, $parentId, $userId);
		$file = $this->nodeHelper->getFileById($folder, $id);
		$pageInfo = $this->getPageByFile($file);

		$this->verifySubpageOrder($subpageOrder);

		$pageInfo->setSubpageOrder($subpageOrder);
		$this->updatePage($pageInfo->getId(), $userId, null, $subpageOrder);
		return $pageInfo;
	}

	/**
	 * @param int    $collectiveId
	 * @param int    $parentId
	 * @param int    $id
	 * @param string $userId
	 *
	 * @return PageInfo
	 * @throws MissingDependencyException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	public function delete(int $collectiveId, int $parentId, int $id, string $userId): PageInfo {
		$this->verifyEditPermissions($collectiveId, $userId);
		$folder = $this->getFolder($collectiveId, $parentId, $userId);
		$file = $this->nodeHelper->getFileById($folder, $id);
		$pageInfo = $this->getPageByFile($file);

		try {
			if (self::isIndexPage($file)) {
				// Don't delete if still page has subpages
				if ($this->pageHasOtherContent($file)) {
					throw new NotPermittedException('Failed to delete page ' . $id . ' with subpages');
				}

				// Delete folder if it's an index page without subpages
				$file->getParent()->delete();
			} else {
				// Delete file if it's not an index page
				$file->delete();
			}
		} catch (InvalidPathException | FilesNotFoundException $e) {
			throw new NotFoundException($e->getMessage(), 0, $e);
		} catch (FilesNotPermittedException $e) {
			throw new NotPermittedException($e->getMessage(), 0, $e);
		}
		$this->pageMapper->deleteByFileId($pageInfo->getId());

		$this->revertSubFolders($folder);
		return $pageInfo;
	}


	/**
	 * @param string   $collectiveName
	 * @param PageInfo $pageInfo
	 * @param bool     $withFileId
	 *
	 * @return string
	 */
	public function getPageLink(string $collectiveName, PageInfo $pageInfo, bool $withFileId = true): string {
		$collectiveRoute = rawurlencode($collectiveName);
		$pagePathRoute = implode('/', array_map('rawurlencode', explode('/', $pageInfo->getFilePath())));
		$pageTitleRoute = rawurlencode($pageInfo->getTitle());
		$fullRoute = implode('/', array_filter([
			$collectiveRoute,
			$pagePathRoute,
			$pageTitleRoute
		]));

		return $withFileId ? $fullRoute . '?fileId=' . $pageInfo->getId() : $fullRoute;
	}

	/**
	 * @param PageInfo $pageInfo
	 * @param string   $content
	 *
	 * @return bool
	 */
	public function matchBacklinks(PageInfo $pageInfo, string $content): bool {
		$prefix = '/\[[^\]]+\]\(';
		$suffix = '\)/';

		$protocol = 'https?:\/\/';
		$trustedDomainConfig = (array)$this->config->getSystemValue('trusted_domains', []);
		$trustedDomains = !empty($trustedDomainConfig) ? '(' . implode('|', $trustedDomainConfig) . ')' : 'localhost';

		$basePath = str_replace('/', '/+', str_replace('/', '/+', preg_quote(trim(\OC::$WEBROOT, '/'), '/'))) . '(\/+index\.php)?';

		$relativeUrl = '(?!' . $protocol . '[^\/]+)';
		$absoluteUrl = $protocol . $trustedDomains . '(:[0-9]+)?';

		$appPath = '\/+apps\/+collectives\/+';

		$pagePath = str_replace('/', '/+', preg_quote($this->getPageLink(explode('/', $pageInfo->getCollectivePath())[1], $pageInfo, false), '/'));
		$fileId = '.+\?fileId=' . $pageInfo->getId();

		$relativeFileIdPattern = $prefix . $relativeUrl . $fileId . $suffix;
		$absoluteFileIdPattern = $prefix . $absoluteUrl . $basePath . $appPath . $fileId . $suffix;

		$relativePathPattern = $prefix . $relativeUrl . $basePath . $appPath . $pagePath . $suffix;
		$absolutePathPattern = $prefix . $absoluteUrl . $basePath . $appPath . $pagePath . $suffix;

		return preg_match($relativeFileIdPattern, $content, $linkMatches) ||
			preg_match($relativePathPattern, $content, $linkMatches) ||
			preg_match($absoluteFileIdPattern, $content, $linkMatches) ||
			preg_match($absolutePathPattern, $content, $linkMatches);
	}

	/**
	 * @param int    $collectiveId
	 * @param int    $parentId
	 * @param int    $id
	 * @param string $userId
	 *
	 * @return PageInfo[]
	 * @throws MissingDependencyException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	public function getBacklinks(int $collectiveId, int $parentId, int $id, string $userId): array {
		$page = $this->find($collectiveId, $parentId, $id, $userId);
		$allPages = $this->findAll($collectiveId, $userId);

		$backlinks = [];
		foreach ($allPages as $p) {
			$file = $this->nodeHelper->getFileById($this->getFolder($collectiveId, $p->getId(), $userId), $p->getId());
			$content = NodeHelper::getContent($file);
			if ($this->matchBacklinks($page, $content)) {
				$backlinks[] = $p;
			}
		}

		return $backlinks;
	}
}
