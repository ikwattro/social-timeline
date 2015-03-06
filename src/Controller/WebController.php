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

        $user = $result->get('user');
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

    public function showUserFeed(Application $application, Request $request)
    {
        $login = $request->get('user_login');
        $neo = $application['neo'];
        $query = 'MATCH (user:User) WHERE user.login = {login}
        MATCH (user)-[:LAST_FEED|PREVIOUS_FEED*]->(feed)
        RETURN user, collect(feed) as feeds';
        $params = ['login' => $login];
        $result = $neo->sendCypherQuery($query, $params)->getResult();

        if (null === $result->get('user')) {
            $application->abort(404, 'The user $login was not found');
        }

        $feeds = $result->get('feeds');

        return $application['twig']->render('show_user_feed.html.twig', array(
            'user' => $result->get('user'),
            'feeds' => $feeds,
        ));
    }

    public function showUserTimeline(Application $application, Request $request)
    {
        $login = $request->get('user_login');
        $neo = $application['neo'];
        $query = 'MATCH (user:User) WHERE user.login = {user_login}
        MATCH (user)-[:FOLLOWS]->(friend)-[:LAST_FEED]->(feed)
        WITH user, friend, feed
        ORDER BY feed.timestamp DESC
        RETURN user, collect({friend: friend, feed: feed}) as timeline
        SKIP 0
        LIMIT 20';
        $params = ['user_login' => $login];
        $result = $neo->sendCypherQuery($query, $params)->getResult();

        if (null === $result->get('user')) {
            $application->abort(404, 'The user $login was not found');
        }

        $user = $result->get('user');
        $timeline = $result->get('timeline');

        return $application['twig']->render('show_timeline.html.twig', array(
            'user' => $result->get('user'),
            'timeline' => $timeline,
        ));


    }
}