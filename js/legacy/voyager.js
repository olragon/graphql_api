Drupal.behaviors.graphql_api_voyager = {
  attach: function (context, settings) {
    function introspectionProvider(introspectionQuery) {
      return new Promise(function (resolve, reject) {
        jQuery.ajax({
          url: settings.basePath + 'graphql',
          type: 'POST',
          contentType: "application/json; charset=utf-8",
          data: JSON.stringify({
            query: introspectionQuery,
          }),
          success: function (data) {
            resolve(data);
          },
          error: function (error) {
            reject(error);
          }
        });
      });
    }

    // Render <Voyager />
    GraphQLVoyager.init(document.getElementById('graphql-api-voyager'), {
      introspection: introspectionProvider
    })
  }
};