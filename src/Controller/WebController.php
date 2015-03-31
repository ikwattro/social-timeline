<?php

namespace Ikwattro\SocialNetwork\Controller;

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;

class WebController
{

    public function home(Application $application, Request $request)
    {
        $neo = $application['neo'];
        $q = 'MATCH (users:User) RETURN users';
        $result = $neo->sendCypherQuery($q)->getResult();

        $users = $result->getNodes();

        return $application['twig']->render('index.html.twig', array(
            'users' => $users
        ));
    }

    public function showUser(Application $application, Request $request, $login)
    {
        $neo = $application['neo'];
        $q = 'MATCH (user:User) WHERE user.login = {login}
         OPTIONAL MATCH (user)-[:FOLLOWS]->(f)
         OPTIONAL MATCH (f)-[:FOLLOWS]->(fof)
         WHERE NOT user.login = fof.login
         AND NOT (user)-[:FOLLOWS]->(fof)
         RETURN user, collect(distinct f) as followed, collect(distinct fof) as suggestions';
        $p = ['login' => $login];
        $result = $neo->sendCypherQuery($q, $p)->getResult();

        $user = $result->getSingle('user');
        $followed = $result->get('followed');
        $suggestions = $result->get('suggestions');

        if (null === $user) {
            $application->abort(404, 'The user $login was not found');
        }

        return $application['twig']->render('show_user.html.twig', array(
            'user' => $user,
            'followed' => $followed,
            'suggestions' => $suggestions
        ));
    }

    public function createRelationship(Application $application, Request $request)
    {
        $neo = $application['neo'];
        $user = $request->get('user');
        $toFollow = $request->get('to_follow');

        $q = 'MATCH (user:User {login: {login}}), (target:User {login:{target}})
        CREATE (user)-[:FOLLOWS]->(target)';
        $p = ['login' => $user, 'target' => $toFollow];
        $neo->sendCypherQuery($q, $p);

        $redirectRoute = $application['url_generator']->generate('show_user', array('login' => $user));

        return $application->redirect($redirectRoute);
    }

    public function removeRelationship(Application $application, Request $request)
    {
        $neo = $application['neo'];
        $user = $request->get('login');
        $toRemove = $request->get('to_remove');

        $q = 'MATCH (user:User {login: {login}} ), (badfriend:User {login: {target}} )
        OPTIONAL MATCH (user)-[r:FOLLOWS]->(badfriend)
        DELETE r';
        $p = ['login' => $user, 'target' => $toRemove];
        $neo->sendCypherQuery($q, $p);

        $redirectRoute = $application['url_generator']->generate('show_user', array('login' => $user));

        return $application->redirect($redirectRoute);
    }

    public function showUserPosts(Application $application, Request $request)
    {
        $login = $request->get('user_login');
        $neo = $application['neo'];
        $query = 'MATCH (user:User) WHERE user.login = {login}
        MATCH (user)-[:LAST_POST]->(latest_post)-[PREVIOUS_POST*0..20]->(post)
        RETURN user, collect(post) as posts';
        $params = ['login' => $login];
        $result = $neo->sendCypherQuery($query, $params)->getResult();

        if (null === $result->get('user')) {
            $application->abort(404, 'The user $login was not found');
        }

        $posts = $result->get('posts');

        return $application['twig']->render('show_user_posts.html.twig', array(
            'user' => $result->getSingle('user'),
            'posts' => $posts,
        ));
    }

    public function showUserTimeline(Application $application, Request $request)
    {
        $login = $request->get('user_login');
        $neo = $application['neo'];
        $query = 'MATCH (user:User) WHERE user.login = {user_login}
        MATCH (user)-[:FOLLOWS]->(friend)-[:LAST_POST]->(latest_post)-[:PREVIOUS_POST*0..10]->(post)
        WITH user, friend, post
        ORDER BY post.timestamp DESC
        SKIP 0
        LIMIT 20
        RETURN user, collect({friend: friend, post: post}) as timeline';
        $params = ['user_login' => $login];
        $result = $neo->sendCypherQuery($query, $params)->getResult();

        if (null === $result->get('user')) {
            $application->abort(404, 'The user $login was not found');
        }

        $user = $result->getSingle('user');
        $timeline = $result->get('timeline');

        return $application['twig']->render('show_timeline.html.twig', array(
            'user' => $result->get('user'),
            'timeline' => $timeline,
        ));
    }

    public function newPost(Application $application, Request $request)
    {
        $title = $request->get('post_title');
        $body = $request->get('post_body');
        $login = $request->get('user_login');
        $query = 'MATCH (user:User) WHERE user.login = {user_login}
        OPTIONAL MATCH (user)-[r:LAST_POST]->(oldPost)
        DELETE r
        CREATE (p:Post)
        SET p.title = {post_title}, p.body = {post_body}
        CREATE (user)-[:LAST_POST]->(p)
        WITH p, collect(oldPost) as oldLatestPosts
        FOREACH (x in oldLatestPosts|CREATE (p)-[:PREVIOUS_POST]->(x))
        RETURN p';
        $params = [
            'user_login' => $login,
            'post_title' => $title,
            'post_body' => $body
            ];
        $result = $application['neo']->sendCypherQuery($query, $params)->getResult();
        if (null !== $result->getSingle('p')) {
            $redirectRoute = $application['url_generator']->generate('user_post', array('user_login' => $login));

            return $application->redirect($redirectRoute);

        }

        $application->abort(500, sprintf('There was a problem inserting a new post for user "%s"', $login));

    }
}