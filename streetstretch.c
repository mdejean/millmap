// function prototype for NYCgeo
#ifdef WIN32
#include "NYCgeo.h"
#else
#include "geo.h"
#define _GNU_SOURCE
#include <dlfcn.h>
#include <linux/limits.h>
#endif
// header file of Work Area layouts
#include "pac.h"

#include <stdio.h>
#include <string.h>
#include <stdlib.h>

 #define min(a,b) \
   ({ __typeof__ (a) _a = (a); \
       __typeof__ (b) _b = (b); \
     _a < _b ? _a : _b; })
 #define max(a,b) \
   ({ __typeof__ (a) _a = (a); \
       __typeof__ (b) _b = (b); \
     _a > _b ? _a : _b; })
   
#ifndef WIN32
#define NYCgeo geo
#endif

struct coord {
    char x[7];
    char y[7];
};

struct coord intersection_coord(char node[7]) {
    C_WA1 wa1 = {
        .input = {
            .func_code = {'2', ' '},
            .platform_ind = 'P',
            .node = {node[0], node[1], node[2], node[3], node[4], node[5], node[6]} 
        }
    };
    C_WA2_F2 wa2 = {};
    
    NYCgeo((char*)&wa1, (char*)&wa2);
    
    struct coord ret;
    
    memcpy(ret.x, wa2.coord[0], 7);
    memcpy(ret.y, wa2.coord[1], 7);
    
    return ret;
}

char function_3s(C_WA1* wa1, C_WA2_F3S* wa2, char boro, const char* on_street, const char* from_street, const char* to_street, char from_dir, char to_dir) {
    memset(wa1, 0, sizeof(*wa1));
    memset(wa2, 0, sizeof(*wa2));
    wa1->input.func_code[0] = '3';
    wa1->input.func_code[1] = 'S';
    wa1->input.platform_ind = 'P';
    
    wa1->input.sti[0].boro = boro;
    memcpy(wa1->input.sti[0].Street_name, 
           on_street, 
           min(sizeof(wa1->input.sti[0].Street_name), strlen(on_street)) );
    if (from_street && to_street) {
        wa1->input.sti[1].boro = boro;
        memcpy(wa1->input.sti[1].Street_name, 
               from_street, 
               min(sizeof(wa1->input.sti[1].Street_name), strlen(from_street)) );
        wa1->input.comp_direction = from_dir;
        
        wa1->input.sti[2].boro = boro;
        memcpy(wa1->input.sti[2].Street_name, 
               to_street, 
               min(sizeof(wa1->input.sti[2].Street_name), strlen(to_street)) );
        wa1->input.comp_direction2 = to_dir;
    }
    
    NYCgeo((char*)wa1, (char*)wa2);
    
    return wa1->output.ret_code[0];
}

int main(int argc, char** argv) {
    C_WA1 wa1 = {};
    C_WA2_F3S wa2 = {};
    
    wa1.input.platform_ind = 'P';
    
    if (argc < 3) {
        puts("streetstretch borough_code on_street [from_street to_street [from_direction [to_direction]]]");
        return 1;
    } else if (argc == 4) {
        puts("must supply both from_street and to_street");
        return 1;
    }
    
#ifndef WIN32
    Dl_info d = {};
    if (dladdr(geo, &d)) {
        char* last_sep;
        last_sep = strrchr(d.dli_fname, '/');
        if (last_sep) {
            last_sep = (char*)memrchr(d.dli_fname, '/', last_sep - d.dli_fname);
            if (last_sep && last_sep - d.dli_fname + 6 < PATH_MAX) {
                char p[PATH_MAX] = {};
                memcpy(p, d.dli_fname, last_sep - d.dli_fname);
                strcpy(&p[last_sep - d.dli_fname], "/fls/");
                setenv("GEOFILES", p, 0);
            }
        }
    }
#endif
    
    char boro = argv[1][0];
    const char* on_street = argv[2];
    
    function_3s(&wa1, &wa2, boro, on_street, argc > 4 ? argv[3] : NULL, argc > 4 ? argv[4] : NULL, 0, 0);
    
    // automatically retry with directions if the section is a dogleg or gap
    if (memcmp(wa1.output.ret_code, "68", 2) == 0) {
        // on street remains the same
        char dirs[2] = {};
        
        for (int from_to = 0; from_to < 2; from_to++) {
            if (argc > 5+from_to) {
                // don't clobber user provided dirs
                dirs[from_to] = argv[5+from_to][0];
                continue;
            }

            // for each of the from,to streets, get the segment from the N to E intersection
            // try N & E first
            
            function_3s(&wa1, &wa2, boro, on_street, argv[3+from_to], argv[3+from_to], 'N', 'E');
            // if 'E is an invalid compass direction' then N & S
            // if 'N is an invalid' or 'input intersections are identical' then W & E
            if (memcmp(wa1.output.ret_code, "38", 2) == 0) {
                if (wa1.output.msg[0] == 'E') {
                    function_3s(&wa1, &wa2, boro, on_street, argv[3+from_to], argv[3+from_to], 'N', 'S');
                } else if (wa1.output.msg[0] == 'N') {
                    function_3s(&wa1, &wa2, boro, on_street, argv[3+from_to], argv[3+from_to], 'W', 'E');
                }
            } else if (memcmp(wa1.output.ret_code, "14", 2) == 0) {
                function_3s(&wa1, &wa2, boro, on_street, argv[3+from_to], argv[3+from_to], 'W', 'E');
            }
            
            // if there was only one intersection for this street (the other one caused the error)
            // then we'll get 'input intersections are identical' again
            if ((memcmp(wa1.output.ret_code, "00", 2) == 0) ||
                (memcmp(wa1.output.ret_code, "01", 2) == 0)) {
                size_t cross_strs_len 
                    = (wa2.nbr_x_str[0] - '0') * 100 
                    + (wa2.nbr_x_str[1] - '0') * 10 
                    + (wa2.nbr_x_str[2] - '0');
                if (cross_strs_len <= 2 && wa2.cross_strs[0].gap_flag != 'N') {
                    // if only one segment and has gap flag = gap or dogleg
                    // use identified direction (1 since it doesn't matter)
                    dirs[from_to] = wa1.input.comp_direction;
                }
            }
        }
        
        // repeat original call with the identified directions
        printf("no! %c %c", dirs[0], dirs[1]);
        
        function_3s(&wa1, &wa2, boro, on_street, argc > 4 ? argv[3] : NULL, argc > 4 ? argv[4] : NULL, dirs[0], dirs[1]);
    }

    
    if ((memcmp(wa1.output.ret_code, "00", 2) == 0) ||
        (memcmp(wa1.output.ret_code, "01", 2) == 0)) {
        size_t cross_strs_len 
            = (wa2.nbr_x_str[0] - '0') * 100 
            + (wa2.nbr_x_str[1] - '0') * 10 
            + (wa2.nbr_x_str[2] - '0');
        // Make sure to end with a real intersection having coordinates
        while (memcmp(intersection_coord(wa2.cross_strs[cross_strs_len-1].node_nbr).x, "       ", 7) == 0)
            cross_strs_len--;
        printf("[");
        for (int i = 0; i < cross_strs_len; i++) {
            struct coord c = intersection_coord(wa2.cross_strs[i].node_nbr);
            if (memcmp(c.x, "       ", 7) == 0) continue;
            printf("{\"x\": \"%.7s\", \"y\": \"%.7s\"%s}", 
                c.x, 
                c.y, 
                wa2.cross_strs[i].gap_flag == 'N' ? ", \"gap\": true" : "");
            if (i != cross_strs_len - 1) {
                printf(",");
            }
        }
        printf("]");
    } else {
         printf("{\"error_code\": \"%c%c\", \"error_message\": \"%.80s\"}",
            wa1.output.ret_code[0], wa1.output.ret_code[1],
            wa1.output.msg);
    }
    
    return 0;
}
