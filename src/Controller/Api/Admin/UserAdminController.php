<?php

namespace App\Controller\Api\Admin;

use App\Dto\Admin\AdminPasswordRequest;
use App\Dto\Admin\AdminUserRequest;
use App\Entity\User;
use App\Presenter\UserPresenter;
use App\Repository\UserRepository;
use App\Service\Admin\UserAdmin;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Accounts, as the admin sees them.
 *
 * The firewall already requires ROLE_MODERATOR for everything under
 * /api/admin. Anything that changes who holds a role, or removes an account
 * outright, asks for ROLE_ADMIN on top: a moderator is here to deal with what
 * people write, not with who they are.
 */
class UserAdminController extends AbstractController
{
    private const MAX_LIMIT = 100;

    private const SORTS = ['recent', 'oldest', 'username', 'name'];

    private const STATUSES = ['active', 'deleted', 'all'];

    #[Route('/api/admin/users', name: 'api_admin_users_index', methods: ['GET'])]
    public function index(Request $request, UserRepository $users): JsonResponse
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = min(self::MAX_LIMIT, max(1, $request->query->getInt('limit', 25)));

        $result = $users->page(
            [
                'q' => $request->query->get('q'),
                'role' => $this->oneOf($request->query->get('role'), User::ASSIGNABLE_ROLES),
                'status' => $this->oneOf($request->query->get('status'), self::STATUSES),
                'sort' => $this->oneOf($request->query->get('sort'), self::SORTS),
            ],
            ($page - 1) * $limit,
            $limit,
        );

        // Counted for the whole page at once; four queries, not four per row.
        $stats = $users->statsFor(array_map(
            static fn (User $user) => (int) $user->getId(),
            $result['items'],
        ));

        return $this->json([
            'items' => array_map(
                static fn (User $user) => UserPresenter::admin($user, $stats[$user->getId()] ?? []),
                $result['items'],
            ),
            'total' => $result['total'],
            'page' => $page,
            'limit' => $limit,
            'pages' => (int) ceil($result['total'] / $limit),
        ]);
    }

    #[Route('/api/admin/users/{id}', name: 'api_admin_users_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, UserRepository $users): JsonResponse
    {
        $user = $this->mustFind($users, $id);
        $stats = $users->statsFor([$id]);

        return $this->json(UserPresenter::admin($user, $stats[$id] ?? []));
    }

    #[Route('/api/admin/users', name: 'api_admin_users_create', methods: ['POST'], format: 'json')]
    #[IsGranted(User::ROLE_ADMIN)]
    public function create(
        #[MapRequestPayload] AdminUserRequest $payload,
        #[CurrentUser] User $actor,
        UserAdmin $admin,
    ): JsonResponse {
        try {
            $user = $admin->create($payload, $actor);
        } catch (\InvalidArgumentException $e) {
            return $this->failure($e);
        }

        return $this->json(UserPresenter::admin($user), Response::HTTP_CREATED);
    }

    #[Route('/api/admin/users/{id}', name: 'api_admin_users_update', methods: ['PATCH'], requirements: ['id' => '\d+'], format: 'json')]
    public function update(
        int $id,
        #[MapRequestPayload] AdminUserRequest $payload,
        #[CurrentUser] User $actor,
        UserRepository $users,
        UserAdmin $admin,
    ): JsonResponse {
        $user = $this->mustFind($users, $id);

        try {
            $admin->update($user, $payload, $actor);
        } catch (\InvalidArgumentException $e) {
            return $this->failure($e);
        }

        $stats = $users->statsFor([$id]);

        return $this->json(UserPresenter::admin($user, $stats[$id] ?? []));
    }

    #[Route('/api/admin/users/{id}/password', name: 'api_admin_users_password', methods: ['POST'], requirements: ['id' => '\d+'], format: 'json')]
    #[IsGranted(User::ROLE_ADMIN)]
    public function password(
        int $id,
        #[MapRequestPayload] AdminPasswordRequest $payload,
        UserRepository $users,
        UserAdmin $admin,
    ): JsonResponse {
        $admin->setPassword($this->mustFind($users, $id), $payload->password);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/admin/users/{id}/avatar', name: 'api_admin_users_avatar_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteAvatar(int $id, UserRepository $users, UserAdmin $admin): JsonResponse
    {
        $user = $this->mustFind($users, $id);
        $admin->removeAvatar($user);

        return $this->json(UserPresenter::admin($user));
    }

    #[Route('/api/admin/users/{id}', name: 'api_admin_users_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function delete(int $id, #[CurrentUser] User $actor, UserRepository $users, UserAdmin $admin): JsonResponse
    {
        try {
            $admin->delete($this->mustFind($users, $id), $actor);
        } catch (\InvalidArgumentException $e) {
            return $this->failure($e);
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/admin/users/{id}/restore', name: 'api_admin_users_restore', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function restore(int $id, UserRepository $users, UserAdmin $admin): JsonResponse
    {
        $user = $this->mustFind($users, $id);

        try {
            $admin->restore($user);
        } catch (\InvalidArgumentException $e) {
            return $this->failure($e);
        }

        return $this->json(UserPresenter::admin($user));
    }

    /* ------------------------------------------------------------- private */

    /** Deleted accounts are still addressable here — that is how you restore one. */
    private function mustFind(UserRepository $users, int $id): User
    {
        $user = $users->find($id);
        if (null === $user) {
            throw new NotFoundHttpException('User not found.');
        }

        return $user;
    }

    /**
     * Which status a refusal deserves. A conflict is somebody else holding the
     * name; a forbidden is the rule about not locking yourself out.
     */
    private function failure(\InvalidArgumentException $e): JsonResponse
    {
        $status = match ($e->getMessage()) {
            'username_already_used', 'email_already_used', 'already_deleted', 'not_deleted' => Response::HTTP_CONFLICT,
            'cannot_delete_self', 'cannot_demote_self', 'roles_require_admin' => Response::HTTP_FORBIDDEN,
            default => Response::HTTP_BAD_REQUEST,
        };

        return $this->json(['error' => $e->getMessage()], $status);
    }

    /**
     * Anything not on the list is treated as "no filter" rather than as an
     * error — a stale bookmark should show a table, not a 400.
     *
     * @param list<string> $allowed
     */
    private function oneOf(mixed $value, array $allowed): ?string
    {
        return \is_string($value) && \in_array($value, $allowed, true) ? $value : null;
    }
}
