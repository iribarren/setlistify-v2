The project setlistify is aimed to create playlists in different music streaming services based on the setlist that a band has already played in the past.

The project will be divided in a backend service that manages the communication between the  service setlist.fm that provides the setlist information, the streaming music services, and persist the info of the concerts that the user attends. The user must be able to add concerts that they want to attend for an specific band, fetch setlists from an online service for that band, create a playlist in their prefered music streaming platform, review and add notes once they have attender the concert etc

The first task is to set up the development environment for the different apps, choosing the tools that are going to be used, establish ground rules to make future development consistent and create a plan for the initial set of featuers. This session should not generate any code. We will generate prompts for the features that we discuss so they are developed in the future one by one, using the existing workflow in the configuration

Since we are going to use external APIs that may require using credentials, security is of utmost importance to the project, and the development enviroment setup must reflect that separating the config for local and production.

The frontend must be cross-platform and should work in web and mobile devices This should be a commercial app,  with some basic features or limits for free users, and extended capabilites for paying users.

These are different features of the app, feel free to propose more in this planning stage

- The users adds a new concert that they want to attend, adding the bands' name (there might be more than one band playing), date of the concert, and some optional data like the venue, price ticket, schedule etc.
-Once that data is introduced, the user can create a playlist in a streaming platform with the setlist the bands playing in the concert have played before. This info must be obtained from setlist.fm service using their public  API
  
There should be 2 playlist creation modes

1. fast mode: here the app will search for the latest show available by the bands in setlist.fm and will try to create a playlist without any more input from the user. This mode can faces problems in every service it uses. In  the setlist.fm service the band may have no info. In the streaming services all or some song of the band may not be avaiable, there could be different versions (studio album, live recorging etc) This problems are a given, and the app should try to do the best it cans to create a playlist

2. Normal mode. here the app will ask the user for more info in every step. First the last concerts with song info in setlist.fm will be shown to the user to choose one. Then the available versions of the song in the streaming service will be shown to the user so they can to choose which one they prefer.

- The user must be able to list the concerts that has attended and the one that wants to attend in the future.
- The user must be able to see the info of a concert that they plan to attend or attended already. In this page the shoud be and embed of the streaming service in order to play the playlist linked to that concert.
- If the user has attender the concert, in the concert page, they should be able to add notes and reviews. This could be shared in socials

Playlist creation is the meetiest part of the project, so at the moment you may produce some prompts to plan and explore them further in the future once the projects works with the user account creation, configuration of apicredentials of the streaming services, the concert creation and some basic features. In any case, playlist creation is part of the MPV of the project

For the rest of the features you must create prompts in the @docs/prompts/ folder that should be able to trigger the implementation workflow. Ask any question you need about them.

Features out of the MPV but that may be worth exploring now are:

- Get info from the bands (name, song ids, pictures), concerts and so on from online services as well.
- Upload video snippets of the concert.
- Different interactions with socials. Post concerts the users plans to attend. Post the video snippets in socials

You may propose some prompt to work on the look and feel of the frontend app doing some design with the tools you are able to use